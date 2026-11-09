<?php

namespace App\Ai\Tools;

use App\Ai\Exceptions\ApprovalAlreadyResolved;
use App\Models\PendingToolCall;
use Illuminate\Support\Facades\DB;

final class ToolApprovals
{
    public function __construct(private readonly ToolRunner $runner) {}

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

        return new ApprovalOutcome($resolved, $this->runner->resume($resolved->run_id));
    }
}
