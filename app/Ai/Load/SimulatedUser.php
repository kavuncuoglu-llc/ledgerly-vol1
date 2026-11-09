<?php

namespace App\Ai\Load;

use App\Models\Conversation;
use App\Models\Reply;

final class SimulatedUser
{
    public ?Conversation $conversation = null;

    public ?Reply $reply = null;

    public int $nextTurn = 0;

    public int $completedTurns = 0;

    public string $outcome = '';

    public string $error = '';

    public function isFinished(): bool
    {
        return $this->outcome !== '';
    }
}
