<?php

namespace App\Ai\Tools;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Exceptions\ApprovalAlreadyResolved;
use App\Ai\Replies\ReplyLifecycle;
use App\Ai\Replies\ResumeReply;
use App\Models\PendingToolCall;
use App\Models\Reply;
use Illuminate\Support\Facades\DB;

final class ToolApprovals
{
    public function __construct(
        private readonly ToolRunner $runner,
        private readonly ConversationStore $conversations,
        private readonly ReplyLifecycle $replies,
    ) {}

    public function approve(PendingToolCall $pending): ApprovalOutcome
    {
        $claimed = $this->claim($pending, PendingToolCall::STATUS_APPROVED);

        $result = $this->runner->executeApproved($claimed);

        $claimed->update([
            'result' => $result->toArray(),
        ]);

        return $this->continueRun($claimed);
    }

    public function decline(PendingToolCall $pending): ApprovalOutcome
    {
        $claimed = $this->claim($pending, PendingToolCall::STATUS_DECLINED);

        $result = $this->runner->recordDecline($claimed);

        $claimed->update([
            'result' => $result->toArray(),
        ]);

        return $this->continueRun($claimed);
    }

    private function claim(PendingToolCall $pending, string $status): PendingToolCall
    {
        return DB::transaction(function () use ($pending, $status): PendingToolCall {
            $locked = PendingToolCall::query()
                ->lockForUpdate()
                ->findOrFail($pending->id);

            if (! $locked->isPending()) {
                throw new ApprovalAlreadyResolved($locked->status);
            }

            $locked->update([
                'status' => $status,
                'resolved_at' => now(),
            ]);

            return $locked;
        });
    }

    private function continueRun(PendingToolCall $resolved): ApprovalOutcome
    {
        if ($resolved->run_id === null) {
            return new ApprovalOutcome($resolved);
        }

        $stillWaiting = PendingToolCall::query()
            ->where('run_id', $resolved->run_id)
            ->where('status', PendingToolCall::STATUS_PENDING)
            ->exists();

        if ($stillWaiting) {
            return new ApprovalOutcome($resolved);
        }

        if ($this->handedToQueue($resolved->run_id)) {
            return new ApprovalOutcome($resolved);
        }

        $run = $this->runner->resume($resolved->run_id);

        $this->recordInConversation($resolved, $run);

        return new ApprovalOutcome($resolved, $run);
    }

    private function handedToQueue(string $runId): bool
    {
        $reply = Reply::query()
            ->withoutGlobalScopes()
            ->where('resume_token', $runId)
            ->where('status', Reply::STATUS_PAUSED)
            ->first();

        if ($reply === null) {
            return false;
        }

        $this->replies->resuming($reply);

        ResumeReply::dispatch($reply->id);

        return true;
    }

    private function recordInConversation(PendingToolCall $resolved, ToolRunResult $run): void
    {
        if ($resolved->conversation_id === null) {
            return;
        }

        $conversation = $this->conversations->find($resolved->conversation_id);

        if (! $conversation->exists) {
            return;
        }

        $this->conversations->appendRun($conversation, $run->forConversation($conversation->id));
    }
}
