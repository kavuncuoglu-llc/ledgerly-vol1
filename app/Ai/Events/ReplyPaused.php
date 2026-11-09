<?php

namespace App\Ai\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyPaused implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $replyId,
        public readonly string $summary,
        public readonly array $approvals,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("reply.{$this->replyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ReplyPaused';
    }

    public function broadcastWith(): array
    {
        return [
            'summary' => $this->summary,
            'approvals' => $this->approvals,
        ];
    }
}
