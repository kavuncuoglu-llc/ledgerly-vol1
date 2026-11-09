<?php

namespace Tests\Support;

use App\Ai\Hardware\HostInspector;

final class FakeHost implements HostInspector
{
    public function __construct(
        private readonly string $operatingSystem,
        private readonly array $commands = [],
        private readonly array $files = [],
    ) {}

    public static function macStudio(int $gibibytes, int $wiredLimitMib = 0, string $chip = 'Apple M2 Ultra'): self
    {
        return new self('Darwin', [
            'sysctl -n hw.memsize' => (string) ($gibibytes * 1_073_741_824),
            'sysctl -n machdep.cpu.brand_string' => $chip,
            'sysctl -n iogpu.wired_limit_mb' => (string) $wiredLimitMib,
        ]);
    }

    public function operatingSystem(): string
    {
        return $this->operatingSystem;
    }

    public function run(array $command): string
    {
        return $this->commands[implode(' ', $command)] ?? '';
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? '';
    }
}
