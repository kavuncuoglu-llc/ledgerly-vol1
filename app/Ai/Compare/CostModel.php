<?php

namespace App\Ai\Compare;

use App\Ai\AiManager;
use App\Ai\Contracts\Usage;

final class CostModel
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal'];

    private const MILLISECONDS_PER_HOUR = 3_600_000;

    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private readonly AiManager $ai) {}

    public function of(string $provider, Usage $usage, float $latencyMs): Cost
    {
        $config = $this->ai->providerConfig($provider);

        $rate = $this->rateOf($provider, (string) ($config['model'] ?? ''));

        if ($rate !== null) {
            return new Cost(
                usd: ($usage->promptTokens * $rate['in'] + $usage->completionTokens * $rate['out']) / self::TOKENS_PER_RATE_UNIT,
                basis: Cost::BASIS_RATE,
            );
        }

        if (isset($config['gpu_hour_cost'])) {
            return new Cost(
                usd: (float) $config['gpu_hour_cost'] * $latencyMs / self::MILLISECONDS_PER_HOUR,
                basis: Cost::BASIS_GPU,
            );
        }

        if ($this->runsOnThisMachine($config)) {
            return new Cost(0.0, Cost::BASIS_FREE);
        }

        return new Cost(0.0, Cost::BASIS_UNKNOWN);
    }

    private function rateOf(string $provider, string $model): ?array
    {
        $rate = (config('ai.rates', [])[$provider] ?? [])[$model] ?? null;

        if (! is_array($rate)) {
            return null;
        }

        $in = (float) ($rate['in'] ?? 0);

        $out = (float) ($rate['out'] ?? 0);

        if ($in === 0.0 && $out === 0.0) {
            return null;
        }

        return [
            'in' => $in,
            'out' => $out,
        ];
    }

    private function runsOnThisMachine(array $config): bool
    {
        if (($config['driver'] ?? '') !== 'openai-compatible') {
            return false;
        }

        $host = parse_url((string) ($config['base_url'] ?? ''), PHP_URL_HOST);

        return in_array($host, self::LOCAL_HOSTS, true);
    }
}
