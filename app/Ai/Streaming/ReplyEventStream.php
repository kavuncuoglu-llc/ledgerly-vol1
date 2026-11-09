<?php

namespace App\Ai\Streaming;

use App\Ai\Replies\ReplyLifecycle;
use App\Models\Reply;
use Closure;
use Illuminate\Support\Sleep;

final class ReplyEventStream
{
    private readonly Closure $write;

    public function __construct(
        private readonly ReplyBuffer $buffer,
        private readonly ReplyLifecycle $lifecycle,
        ?Closure $write = null,
    ) {
        $this->write = $write ?? $this->writeToOutput(...);
    }

    public function relay(Reply $reply, int $offset = 0): void
    {
        $deadline = now()->addSeconds((int) config('ai.replies.sse_max_seconds'));

        while (true) {
            $current = Reply::query()->withoutGlobalScopes()->findOrFail($reply->id);

            $offset = $this->drain($reply->id, $offset);

            if ($current->isFinished() || $current->isPaused()) {
                $this->emitEnd($current);

                return;
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                return;
            }

            if (connection_aborted()) {
                return;
            }

            Sleep::usleep((int) config('ai.replies.sse_poll_ms') * 1000);
        }
    }

    private function drain(string $replyId, int $offset): int
    {
        foreach ($this->buffer->after($replyId, $offset) as $sequence => $text) {
            $this->emit('ReplyDelta', [
                't' => $text,
                'n' => $sequence,
            ], $sequence);

            $offset = $sequence + 1;
        }

        return $offset;
    }

    private function emitEnd(Reply $reply): void
    {
        if ($reply->isPaused()) {
            $this->emit('ReplyPaused', [
                'summary' => (string) $reply->paused_for,
                'approvals' => $this->lifecycle->approvalsFor($reply),
            ]);

            return;
        }

        if ($reply->status === Reply::STATUS_COMPLETED) {
            $this->emit('ReplyCompleted', [
                'sources' => $reply->sources ?? [],
                'citations_ok' => $reply->citations_ok,
            ]);

            return;
        }

        $this->emit('ReplyFailed', [
            'message' => (string) $reply->content,
        ]);
    }

    private function emit(string $event, array $data, ?int $id = null): void
    {
        $frame = $id === null
            ? ''
            : "id: {$id}\n";

        $frame .= "event: {$event}\ndata: ".json_encode($data)."\n\n";

        ($this->write)($frame);
    }

    private function writeToOutput(string $frame): void
    {
        echo $frame;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
