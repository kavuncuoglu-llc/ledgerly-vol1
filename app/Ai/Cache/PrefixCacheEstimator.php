<?php

namespace App\Ai\Cache;

use App\Ai\Tokens\TokenCounter;
use Illuminate\Support\Facades\Cache;

final class PrefixCacheEstimator
{
    public function __construct(private readonly TokenCounter $counter) {}

    public function estimate(string $feature, ?int $conversationId, array $messages, int $promptTokens): int
    {
        $key = "ai:prefix:{$feature}:{$conversationId}";

        $current = $this->render($messages);

        $previous = (string) Cache::get($key, '');

        Cache::put($key, $current, now()->addMinutes((int) config('ai.prompt_cache.prefix_memory_minutes')));

        if ($previous === '') {
            return 0;
        }

        $shared = $this->sharedPrefix($previous, $current);

        return min($promptTokens, $this->counter->count($shared));
    }

    public function render(array $messages): string
    {
        $rendered = '';

        foreach ($messages as $message) {
            $rendered .= $message['role']."\n".$this->contentOf($message)."\n\n";
        }

        return $rendered;
    }

    public function sharedPrefix(string $first, string $second): string
    {
        $limit = min(strlen($first), strlen($second));

        $length = 0;

        while ($length < $limit && $first[$length] === $second[$length]) {
            $length++;
        }

        return mb_strcut($first, 0, $length);
    }

    private function contentOf(array $message): string
    {
        $content = (string) ($message['content'] ?? '');

        if (empty($message['tool_calls'])) {
            return $content;
        }

        return $content.json_encode($message['tool_calls']);
    }
}
