<?php

namespace App\Ai\Vllm;

final readonly class VllmMetrics
{
    private const PREFIX = 'vllm:';

    private const LINE = '/^vllm:([a-z0-9_]+)(?:\{(.*)\})?\s+(\S+)(?:\s+\d+)?$/';

    private const LABEL = '/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/';

    public function __construct(private array $samples) {}

    public static function parse(string $text): self
    {
        $samples = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if (! str_starts_with($line, self::PREFIX)) {
                continue;
            }

            if (preg_match(self::LINE, $line, $matches) !== 1) {
                continue;
            }

            if (! is_numeric($matches[3])) {
                continue;
            }

            $samples[self::normalize($matches[1])][] = [
                'labels' => self::parseLabels($matches[2]),
                'value' => (float) $matches[3],
            ];
        }

        return new self($samples);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->samples);
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    public function sum(string $name): float
    {
        return array_sum(array_column($this->samples[$name] ?? [], 'value'));
    }

    public function labels(string $name): array
    {
        return $this->samples[$name][0]['labels'] ?? [];
    }

    public function prefixCacheHitRatio(): float
    {
        $queries = $this->sum('prefix_cache_queries');

        if ($queries === 0.0) {
            return 0.0;
        }

        return $this->sum('prefix_cache_hits') / $queries;
    }

    public function cacheConfig(): array
    {
        return $this->labels('cache_config_info');
    }

    public function kvCacheTokens(): int
    {
        $labels = $this->cacheConfig();

        if (is_numeric($labels['kv_cache_size_tokens'] ?? null)) {
            return (int) $labels['kv_cache_size_tokens'];
        }

        if (! is_numeric($labels['num_gpu_blocks'] ?? null)) {
            return 0;
        }

        return (int) $labels['num_gpu_blocks'] * (int) ($labels['block_size'] ?? 0);
    }

    public function kvCacheMaxConcurrency(): float
    {
        $labels = $this->cacheConfig();

        if (! is_numeric($labels['kv_cache_max_concurrency'] ?? null)) {
            return 0.0;
        }

        return (float) $labels['kv_cache_max_concurrency'];
    }

    public function prefixCaching(): string
    {
        $declared = $this->cacheConfig()['enable_prefix_caching'] ?? '';

        if ($declared === '') {
            return 'unknown';
        }

        if (strtolower((string) $declared) === 'true') {
            return 'on';
        }

        return 'off';
    }

    private static function normalize(string $name): string
    {
        if (str_ends_with($name, '_total')) {
            return substr($name, 0, -6);
        }

        return $name;
    }

    private static function parseLabels(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        preg_match_all(self::LABEL, $raw, $matches, PREG_SET_ORDER);

        $labels = [];

        foreach ($matches as $match) {
            $labels[$match[1]] = stripcslashes($match[2]);
        }

        return $labels;
    }
}
