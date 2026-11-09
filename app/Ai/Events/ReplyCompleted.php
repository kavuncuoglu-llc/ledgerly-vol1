<?php

namespace App\Ai\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyCompleted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $replyId,
        public readonly array $sources,
        public readonly bool $citationsOk,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("reply.{$this->replyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ReplyCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'sources' => $this->sources,
            'citations_ok' => $this->citationsOk,
        ];
    }
}
