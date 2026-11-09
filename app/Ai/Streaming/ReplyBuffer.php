<?php

namespace App\Ai\Streaming;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class ReplyBuffer
{
    public function append(string $replyId, string $text): int
    {
        $chunks = $this->chunks($replyId);

        $chunks[] = $text;

        $this->store()->put($this->chunksKey($replyId), $chunks, $this->ttl('buffer_ttl_seconds'));

        return count($chunks) - 1;
    }

    public function chunks(string $replyId): array
    {
        return $this->store()->get($this->chunksKey($replyId), []);
    }

    public function count(string $replyId): int
    {
        return count($this->chunks($replyId));
    }

    public function after(string $replyId, int $offset): array
    {
        return array_slice($this->chunks($replyId), max($offset, 0), null, true);
    }

    public function text(string $replyId): string
    {
        return implode('', $this->chunks($replyId));
    }

    public function finish(string $replyId): void
    {
        $this->store()->put($this->chunksKey($replyId), $this->chunks($replyId), $this->ttl('buffer_done_ttl_seconds'));
    }

    public function forget(string $replyId): void
    {
        $this->store()->forget($this->chunksKey($replyId));
    }

    private function store(): Repository
    {
        return Cache::store(config('ai.replies.buffer_store'));
    }

    private function chunksKey(string $replyId): string
    {
        return "reply-buffer:{$replyId}";
    }

    private function ttl(string $setting): int
    {
        return (int) config("ai.replies.{$setting}");
    }
}
