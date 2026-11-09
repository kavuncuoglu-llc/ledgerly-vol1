<?php

namespace App\Ai\Replies;

use Throwable;

final class ReplyBroadcaster
{
    private float $pausedUntil = 0.0;

    public function streaming(object $event): void
    {
        if (microtime(true) < $this->pausedUntil) {
            return;
        }

        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);

            $this->pausedUntil = microtime(true) + (float) config('ai.replies.broadcast_pause_seconds');
        }
    }

    public function notify(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
