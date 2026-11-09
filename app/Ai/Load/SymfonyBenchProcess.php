<?php

namespace App\Ai\Load;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SymfonyBenchProcess implements BenchProcess
{
    public function isAvailable(string $binary): bool
    {
        if (is_file($binary)) {
            return is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    public function run(array $command, array $environment, int $timeoutSeconds): BenchProcessResult
    {
        $process = new Process($command, null, $environment, null, $timeoutSeconds);

        $process->run();

        return new BenchProcessResult(
            exitCode: (int) $process->getExitCode(),
            output: trim($process->getOutput()."\n".$process->getErrorOutput()),
        );
    }
}
