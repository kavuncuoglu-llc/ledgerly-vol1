<?php

namespace App\Ai\Triton;

final readonly class TritonMetrics
{
    private const LINE = '/^(nv_[a-z0-9_]+)(?:\{(.*)\})?\s+(\S+)(?:\s+\d+)?$/';

    private const LABEL = '/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/';

    public function __construct(private array $samples) {}

    public static function parse(string $text): self
    {
        $samples = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if (preg_match(self::LINE, $line, $matches) !== 1) {
                continue;
            }

            if (! is_numeric($matches[3])) {
                continue;
            }

            $samples[$matches[1]][] = [
                'labels' => self::parseLabels($matches[2]),
                'value' => (float) $matches[3],
            ];
        }

        return new self($samples);
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->samples);
    }

    public function models(): array
    {
        $models = [];

        foreach ($this->samples['nv_inference_request_success'] ?? [] as $sample) {
            $models[] = (string) ($sample['labels']['model'] ?? '');
        }

        return array_values(array_unique(array_filter($models, fn (string $model): bool => $model !== '')));
    }

    public function forModel(string $name, string $model): float
    {
        $total = 0.0;

        foreach ($this->samples[$name] ?? [] as $sample) {
            if (($sample['labels']['model'] ?? null) === $model) {
                $total += $sample['value'];
            }
        }

        return $total;
    }

    public function total(string $name): float
    {
        return array_sum(array_column($this->samples[$name] ?? [], 'value'));
    }

    public function averageBatchSize(string $model): float
    {
        $executions = $this->forModel('nv_inference_exec_count', $model);

        if ($executions === 0.0) {
            return 0.0;
        }

        return $this->forModel('nv_inference_count', $model) / $executions;
    }

    public function averageQueueMilliseconds(string $model): float
    {
        $requests = $this->forModel('nv_inference_request_success', $model);

        if ($requests === 0.0) {
            return 0.0;
        }

        return $this->forModel('nv_inference_queue_duration_us', $model) / $requests / 1000;
    }

    public function failures(string $model): int
    {
        return (int) $this->forModel('nv_inference_request_failure', $model);
    }

    public function successes(string $model): int
    {
        return (int) $this->forModel('nv_inference_request_success', $model);
    }

    public function hasGpu(): bool
    {
        return $this->has('nv_gpu_utilization');
    }

    public function gpuUtilization(): float
    {
        if (! $this->hasGpu()) {
            return 0.0;
        }

        $values = array_column($this->samples['nv_gpu_utilization'], 'value');

        return array_sum($values) / count($values);
    }

    public function gpuMemoryUsedFraction(): float
    {
        $total = $this->total('nv_gpu_memory_total_bytes');

        if ($total === 0.0) {
            return 0.0;
        }

        return $this->total('nv_gpu_memory_used_bytes') / $total;
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
