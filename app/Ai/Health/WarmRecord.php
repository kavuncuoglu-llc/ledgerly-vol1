<?php

namespace App\Ai\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class WarmRecord
{
    private const KEY_PREFIX = 'ai:warm:';

    private const TTL_SECONDS = 604800;

    public function remember(string $provider, array $models, float $seconds): void
    {
        Cache::store(config('ai.breaker.store'))->put(self::KEY_PREFIX.$provider, [
            'at' => CarbonImmutable::now()->getTimestamp(),
            'models' => $models,
            'seconds' => $seconds,
        ], self::TTL_SECONDS);
    }

    public function last(string $provider): array
    {
        $record = Cache::store(config('ai.breaker.store'))->get(self::KEY_PREFIX.$provider);

        if (! is_array($record)) {
            return [];
        }

        return $record;
    }
}
