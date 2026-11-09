<?php

namespace App\Ai\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyDelta implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $replyId,
        public readonly string $text,
        public readonly int $sequence,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("reply.{$this->replyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ReplyDelta';
    }

    public function broadcastWith(): array
    {
        return [
            't' => $this->text,
            'n' => $this->sequence,
        ];
    }
}
