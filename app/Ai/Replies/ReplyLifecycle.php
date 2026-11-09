<?php

namespace App\Ai\Replies;

use App\Ai\Events\ModelCalled;
use App\Ai\Events\ReplyCompleted;
use App\Ai\Events\ReplyFailed;
use App\Ai\Events\ReplyPaused;
use App\Ai\Events\ReplyRestarted;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Streaming\ReplyBuffer;
use App\Ai\Tools\ToolResult;
use App\Ai\Tools\ToolRunResult;
use App\Models\PendingToolCall;
use App\Models\Reply;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ReplyLifecycle
{
    public const FAILURE_MESSAGE = 'Sorry, something went wrong. Our team has been notified.';

    public const EXPIRY_MESSAGE = 'This request expired before it was approved.';

    public function __construct(
        private readonly ReplyBuffer $buffer,
        private readonly ReplyBroadcaster $broadcaster,
    ) {}

    public function stream(Reply $reply): ReplyStream
    {
        return new ReplyStream($reply, $this->buffer, $this->broadcaster);
    }

    public function begin(Reply $reply, bool $restarted = false): void
    {
        $reply->update([
            'status' => Reply::STATUS_STREAMING,
            'error' => null,
        ]);

        if (! $restarted) {
            return;
        }

        $this->buffer->forget($reply->id);

        $this->broadcaster->notify(new ReplyRestarted($reply->id));
    }

    public function complete(Reply $reply, ToolRunResult $result): void
    {
        $reply->update([
            'status' => Reply::STATUS_COMPLETED,
            'content' => $result->answer(),
            'tokens' => $result->response->usage->completionTokens,
            'sources' => $result->sources,
            'citations_ok' => $result->citationsOk,
            'paused_for' => null,
            'resume_token' => null,
            'paused_at' => null,
            'completed_at' => now(),
        ]);

        $this->buffer->finish($reply->id);

        $this->broadcaster->notify(new ReplyCompleted($reply->id, $result->sources, $result->citationsOk));
    }

    public function pause(Reply $reply, ToolRunResult $result): void
    {
        $pending = $this->pendingCalls($result);

        $summary = $pending->pluck('summary')->implode('; ');

        $reply->update([
            'status' => Reply::STATUS_PAUSED,
            'resume_token' => $pending->first()->run_id,
            'paused_for' => $summary,
            'paused_at' => now(),
        ]);

        $this->buffer->finish($reply->id);

        $this->broadcaster->notify(new ReplyPaused($reply->id, $summary, $this->approvals($pending)));
    }

    public function resuming(Reply $reply): void
    {
        $reply->update(['status' => Reply::STATUS_RESUMING]);
    }

    public function fail(Reply $reply, string $reason): void
    {
        $reply->update([
            'status' => Reply::STATUS_FAILED,
            'content' => self::FAILURE_MESSAGE,
            'error' => Str::limit($reason, 250, ''),
        ]);

        $this->buffer->finish($reply->id);

        $this->recordFailedCall($reply);

        $this->broadcaster->notify(new ReplyFailed($reply->id, self::FAILURE_MESSAGE));
    }

    public function expire(Reply $reply): void
    {
        PendingToolCall::query()
            ->where('run_id', $reply->resume_token)
            ->where('status', PendingToolCall::STATUS_PENDING)
            ->update([
                'status' => PendingToolCall::STATUS_EXPIRED,
                'resolved_at' => now(),
            ]);

        $reply->update([
            'status' => Reply::STATUS_EXPIRED,
            'content' => self::EXPIRY_MESSAGE,
        ]);

        $this->buffer->forget($reply->id);

        $this->broadcaster->notify(new ReplyFailed($reply->id, self::EXPIRY_MESSAGE));
    }

    public function approvalsFor(Reply $reply): array
    {
        if (! $reply->isPaused()) {
            return [];
        }

        $pending = PendingToolCall::query()
            ->where('run_id', $reply->resume_token)
            ->where('status', PendingToolCall::STATUS_PENDING)
            ->orderBy('position')
            ->get();

        return $this->approvals($pending);
    }

    private function pendingCalls(ToolRunResult $result): Collection
    {
        $ids = array_map(fn (ToolResult $pending): string => $pending->pendingId, $result->pending);

        return PendingToolCall::query()
            ->whereIn('id', $ids)
            ->orderBy('position')
            ->get();
    }

    private function approvals(Collection $pending): array
    {
        return $pending
            ->map(fn (PendingToolCall $call): array => [
                'id' => $call->id,
                'summary' => $call->summary,
                'approve_url' => url("support/approve/{$call->id}"),
                'decline_url' => url("support/decline/{$call->id}"),
            ])
            ->values()
            ->all();
    }

    private function recordFailedCall(Reply $reply): void
    {
        event(new ModelCalled(
            feature: 'support.reply',
            provider: config('ai.provider'),
            model: config('ai.model'),
            usage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
            ],
            finishReason: 'job_failed',
            latencyMs: 0,
            conversationId: $reply->conversation_id,
            promptVersion: SupportPrompt::VERSION,
        ));
    }
}
