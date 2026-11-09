<?php

namespace App\Ai\Http;

use App\Ai\Exceptions\ModelRequestFailed;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use Throwable;

final class RetryPolicy
{
    private const RETRYABLE = [408, 409, 425, 429, 500, 502, 503, 504, 529];

    private const ATTEMPTS = 3;

    private const BASE_DELAY_MILLISECONDS = 500;

    private const MAX_JITTER_MILLISECONDS = 250;

    public function run(Closure $attempt): mixed
    {
        $number = 1;

        while (true) {
            try {
                return $attempt();
            } catch (Throwable $exception) {
                $this->pauseBeforeRetry($number, $exception);

                $number++;
            }
        }
    }

    public function pauseBeforeRetry(int $attempt, Throwable $exception): void
    {
        if ($attempt >= self::ATTEMPTS) {
            throw $exception;
        }

        if (! $this->isRetryable($exception)) {
            throw $exception;
        }

        Sleep::usleep($this->delayMilliseconds($attempt, $exception) * 1000);
    }

    public function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof ModelRequestFailed) {
            return $exception->isRetryable();
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
        if ($exception instanceof ModelRequestFailed) {
            return $exception->retryAfterSeconds;
        }

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
