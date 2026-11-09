<?php

namespace App\Ai\Load;

final readonly class BenchResult
{
    public function __construct(
        public int $concurrency,
        public int $completed,
        public int $failed,
        public float $requestsPerSecond,
        public float $outputTokensPerSecond,
        public float $ttftP50,
        public float $ttftP99,
        public float $itlP50,
        public float $itlP99,
    ) {}

    public static function fromJson(int $concurrency, array $result): self
    {
        return new self(
            concurrency: $concurrency,
            completed: (int) ($result['completed'] ?? 0),
            failed: (int) ($result['failed'] ?? 0),
            requestsPerSecond: (float) ($result['request_throughput'] ?? 0),
            outputTokensPerSecond: (float) ($result['output_throughput'] ?? 0),
            ttftP50: (float) ($result['median_ttft_ms'] ?? 0),
            ttftP99: (float) ($result['p99_ttft_ms'] ?? 0),
            itlP50: (float) ($result['median_itl_ms'] ?? 0),
            itlP99: (float) ($result['p99_itl_ms'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'concurrency' => $this->concurrency,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'request_throughput' => $this->requestsPerSecond,
            'output_throughput' => $this->outputTokensPerSecond,
            'ttft_p50_ms' => $this->ttftP50,
            'ttft_p99_ms' => $this->ttftP99,
            'itl_p50_ms' => $this->itlP50,
            'itl_p99_ms' => $this->itlP99,
        ];
    }
}
