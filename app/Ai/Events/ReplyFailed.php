<?php

namespace App\Ai\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyFailed implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $replyId,
        public readonly string $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("reply.{$this->replyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ReplyFailed';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
