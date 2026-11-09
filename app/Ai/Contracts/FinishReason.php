<?php

namespace App\Ai\Contracts;

enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    case ContentFilter = 'content_filter';
    case Truncated = 'truncated';

    public static function normalize(?string $provided, array $vocabulary, string $provider = 'unknown'): self
    {
        if ($provided === null) {
            return self::Stop;
        }

        $reason = $vocabulary[$provided] ?? null;

        if ($reason instanceof self) {
            return $reason;
        }

        logger()->warning('Unrecognized finish reason treated as truncated', [
            'provider' => $provider,
            'finish_reason' => $provided,
        ]);

        return self::Truncated;
    }
}
