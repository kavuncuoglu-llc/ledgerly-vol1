<?php

namespace App\Ai\Drivers\Support;

final class DebugLog
{
    public function enabled(string $feature, ?int $conversationId): bool
    {
        if (in_array($feature, (array) config('ai.debug.features', []), true)) {
            return true;
        }

        if ($conversationId === null) {
            return false;
        }

        return in_array($conversationId, (array) config('ai.debug.conversations', []), true);
    }

    public function write(string $provider, string $feature, ?int $conversationId, string $path, array $request, mixed $response): void
    {
        $line = json_encode([
            'at' => now()->toIso8601String(),
            'provider' => $provider,
            'feature' => $feature,
            'path' => $path,
            'request' => $request,
            'response' => $response,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        file_put_contents($this->fileFor($feature, $conversationId), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function fileFor(string $feature, ?int $conversationId): string
    {
        $suffix = $conversationId === null
            ? preg_replace('/[^a-z0-9._-]/i', '-', $feature)
            : (string) $conversationId;

        return storage_path("logs/ai-debug-{$suffix}.log");
    }
}
