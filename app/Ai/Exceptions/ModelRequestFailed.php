<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

class ModelRequestFailed extends RuntimeException
{
    private const RETRYABLE_STATUSES = [408, 409, 425, 500, 502, 503, 504, 529];

    public function __construct(
        string $message = '',
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $baseUrl, Throwable $previous): ProviderUnavailable
    {
        return new ProviderUnavailable("Could not reach the model server at {$baseUrl}. Is it running?", previous: $previous);
    }

    public static function rejected(int $status, string $body, ?int $retryAfterSeconds = null): self
    {
        $message = "The model server answered HTTP {$status}: {$body}";

        if ($status === 429) {
            return new RateLimited($message, $status, $retryAfterSeconds);
        }

        if (in_array($status, self::RETRYABLE_STATUSES, true)) {
            return new ProviderUnavailable($message, $status, $retryAfterSeconds);
        }

        if (self::mentionsContextOverflow($body)) {
            return new ContextTooLong($message, $status);
        }

        return new InvalidRequest($message, $status);
    }

    public static function malformedStreamChunk(string $payload): self
    {
        return new self("The model server sent a stream chunk that is not a JSON object: {$payload}");
    }

    public static function malformed(string $expected = 'choices[0].message.content'): self
    {
        return new self("The model server answered with a body that has no {$expected}.");
    }

    public function isRetryable(): bool
    {
        return false;
    }

    private static function mentionsContextOverflow(string $body): bool
    {
        return preg_match('/context[_ ]length|context window|maximum context|prompt is too long|too many tokens|exceeds the (maximum|limit)|input token count/i', $body) === 1;
    }
}
