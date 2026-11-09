<?php

namespace App\Ai\Replies;

use App\Ai\Events\ReplyDelta;
use App\Ai\Events\ReplyToolStarted;
use App\Ai\Streaming\DeltaBatcher;
use App\Ai\Streaming\ReplyBuffer;
use App\Ai\Streaming\StreamTaps;
use App\Models\Reply;

final class ReplyStream
{
    private readonly DeltaBatcher $batcher;

    public function __construct(
        private readonly Reply $reply,
        private readonly ReplyBuffer $buffer,
        private readonly ReplyBroadcaster $broadcaster,
    ) {
        $this->batcher = new DeltaBatcher(
            flush: fn (string $text) => $this->publish($text),
            maxMilliseconds: (int) config('ai.replies.flush_ms'),
            maxDeltas: (int) config('ai.replies.flush_tokens'),
        );
    }

    public function taps(): StreamTaps
    {
        return new StreamTaps(
            onDelta: fn (string $delta) => $this->batcher->push($delta),
            onToolStart: fn (string $tool) => $this->announce($tool),
        );
    }

    public function flush(): void
    {
        $this->batcher->flushNow();
    }

    private function publish(string $text): void
    {
        $sequence = $this->buffer->append($this->reply->id, $text);

        $this->broadcaster->streaming(new ReplyDelta($this->reply->id, $text, $sequence));
    }

    private function announce(string $tool): void
    {
        $this->batcher->flushNow();

        $this->broadcaster->streaming(new ReplyToolStarted($this->reply->id, $tool, $this->labelFor($tool)));
    }

    private function labelFor(string $tool): string
    {
        return config("ai.replies.tool_labels.{$tool}")
            ?? config('ai.replies.default_tool_label');
    }
}
