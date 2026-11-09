<?php

namespace App\Ai\Load;

final readonly class BenchProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
