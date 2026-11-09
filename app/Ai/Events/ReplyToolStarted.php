<?php

namespace App\Ai\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyToolStarted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $replyId,
        public readonly string $tool,
        public readonly string $label,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("reply.{$this->replyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ToolCallStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'tool' => $this->tool,
            'label' => $this->label,
        ];
    }
}
