<?php

namespace App\Ai\Hardware;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final class SystemHostInspector implements HostInspector
{
    private const COMMAND_TIMEOUT_SECONDS = 5;

    public function operatingSystem(): string
    {
        return PHP_OS_FAMILY;
    }

    public function run(array $command): string
    {
        try {
            $process = new Process($command);

            $process->setTimeout(self::COMMAND_TIMEOUT_SECONDS);

            $process->run();
        } catch (ExceptionInterface) {
            return '';
        }

        if (! $process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }

    public function read(string $path): string
    {
        if (! is_readable($path)) {
            return '';
        }

        return trim((string) file_get_contents($path));
    }
}
