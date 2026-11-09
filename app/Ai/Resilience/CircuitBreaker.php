<?php

namespace App\Ai\Resilience;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class CircuitBreaker
{
    private const KEY_PREFIX = 'ai:breaker:';

    public function isEnabled(): bool
    {
        return (bool) config('ai.breaker.enabled');
    }

    public function threshold(): int
    {
        return max(1, (int) config('ai.breaker.threshold'));
    }

    public function openSeconds(): int
    {
        return max(1, (int) config('ai.breaker.open_seconds'));
    }

    public function state(string $provider): BreakerState
    {
        $record = $this->store()->get($this->key($provider));

        if (! is_array($record)) {
            return BreakerState::closed();
        }

        $failures = (int) ($record['failures'] ?? 0);

        if ($failures < $this->threshold()) {
            return BreakerState::closed($failures);
        }

        $retryAt = CarbonImmutable::createFromTimestamp((int) ($record['opened_until'] ?? 0));

        $openedAt = CarbonImmutable::createFromTimestamp((int) ($record['opened_at'] ?? 0));

        if ($retryAt->isFuture()) {
            return new BreakerState(BreakerState::OPEN, $failures, $openedAt, $retryAt);
        }

        return new BreakerState(BreakerState::HALF_OPEN, $failures, $openedAt, $retryAt);
    }

    public function isOpen(string $provider): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return $this->state($provider)->isOpen();
    }

    public function recordFailure(string $provider): BreakerState
    {
        if (! $this->isEnabled()) {
            return BreakerState::closed();
        }

        $failures = $this->state($provider)->failures + 1;

        $now = CarbonImmutable::now();

        $record = [
            'failures' => $failures,
            'opened_at' => 0,
            'opened_until' => 0,
        ];

        if ($failures >= $this->threshold()) {
            $record['opened_at'] = $now->getTimestamp();
            $record['opened_until'] = $now->addSeconds($this->openSeconds())->getTimestamp();
        }

        $this->store()->put($this->key($provider), $record, $this->memorySeconds());

        return $this->state($provider);
    }

    public function recordSuccess(string $provider): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->state($provider)->failures === 0) {
            return;
        }

        $this->store()->forget($this->key($provider));
    }

    public function reset(string $provider): void
    {
        $this->store()->forget($this->key($provider));
    }

    private function memorySeconds(): int
    {
        return max($this->openSeconds() * 5, (int) config('ai.breaker.memory_seconds'));
    }

    private function key(string $provider): string
    {
        return self::KEY_PREFIX.$provider;
    }

    private function store(): Repository
    {
        return Cache::store(config('ai.breaker.store'));
    }
}
