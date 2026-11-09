<?php

namespace App\Ai\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

final class RetryPolicy
{
    private const RETRYABLE = [408, 409, 425, 429, 500, 502, 503, 504, 529];

    private const ATTEMPTS = 3;

    private const BASE_DELAY_MILLISECONDS = 500;

    private const MAX_JITTER_MILLISECONDS = 250;

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->retry(
            times: self::ATTEMPTS,
            sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->delayMilliseconds($attempt, $exception),
            when: fn (Throwable $exception): bool => $this->isRetryable($exception),
            throw: true,
        );
    }

    public function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        return in_array($exception->response->status(), self::RETRYABLE, true);
    }

    public function delayMilliseconds(int $attempt, ?Throwable $exception): int
    {
        $retryAfter = $this->retryAfterSeconds($exception);

        if ($retryAfter !== null) {
            return $retryAfter * 1000;
        }

        return (int) (self::BASE_DELAY_MILLISECONDS * (2 ** ($attempt - 1)) + random_int(0, self::MAX_JITTER_MILLISECONDS));
    }

    private function retryAfterSeconds(?Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $header = $exception->response->header('Retry-After');

        if (! is_numeric($header)) {
            return null;
        }

        return (int) $header;
    }
}
