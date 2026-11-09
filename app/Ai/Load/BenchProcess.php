<?php

namespace App\Ai\Load;

interface BenchProcess
{
    public function isAvailable(string $binary): bool;

    public function run(array $command, array $environment, int $timeoutSeconds): BenchProcessResult;
}
