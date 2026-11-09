<?php

namespace Tests\Support;

use App\Ai\Load\BenchProcess;
use App\Ai\Load\BenchProcessResult;

class FakeBenchProcess implements BenchProcess
{
    public const CURVE = [
        1 => ['request_throughput' => 0.9, 'output_throughput' => 98.0, 'median_ttft_ms' => 210.0, 'p99_ttft_ms' => 320.0, 'median_itl_ms' => 10.0, 'p99_itl_ms' => 14.0],
        8 => ['request_throughput' => 6.5, 'output_throughput' => 710.0, 'median_ttft_ms' => 260.0, 'p99_ttft_ms' => 450.0, 'median_itl_ms' => 12.0, 'p99_itl_ms' => 19.0],
        16 => ['request_throughput' => 11.8, 'output_throughput' => 1290.0, 'median_ttft_ms' => 340.0, 'p99_ttft_ms' => 720.0, 'median_itl_ms' => 15.0, 'p99_itl_ms' => 26.0],
        32 => ['request_throughput' => 17.9, 'output_throughput' => 1960.0, 'median_ttft_ms' => 610.0, 'p99_ttft_ms' => 1900.0, 'median_itl_ms' => 22.0, 'p99_itl_ms' => 41.0],
    ];

    public array $commands = [];

    public array $environments = [];

    public function __construct(
        private readonly bool $available = true,
        private readonly int $exitCode = 0,
        private readonly bool $writesResult = true,
        private readonly array $curve = self::CURVE,
    ) {}

    public function isAvailable(string $binary): bool
    {
        return $this->available;
    }

    public function run(array $command, array $environment, int $timeoutSeconds): BenchProcessResult
    {
        $this->commands[] = $command;

        $this->environments[] = $environment;

        if ($this->exitCode !== 0) {
            return new BenchProcessResult($this->exitCode, "line 1\nline 2\nTraceback: connection refused");
        }

        if ($this->writesResult) {
            $this->writeResult($command);
        }

        return new BenchProcessResult(0, 'Benchmark finished');
    }

    private function writeResult(array $command): void
    {
        $concurrency = (int) $this->valueOf($command, '--max-concurrency');

        $directory = $this->valueOf($command, '--result-dir');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $directory.'/'.$this->valueOf($command, '--result-filename'),
            json_encode([
                'completed' => 32,
                'failed' => 0,
                ...($this->curve[$concurrency] ?? $this->curve[1]),
            ]),
        );
    }

    private function valueOf(array $command, string $flag): string
    {
        return (string) $command[array_search($flag, $command, true) + 1];
    }
}
