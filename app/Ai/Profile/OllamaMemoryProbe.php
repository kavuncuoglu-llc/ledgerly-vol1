<?php

namespace App\Ai\Profile;

use App\Ai\Hardware\HostInspector;
use App\Ai\Hardware\OllamaClient;

final class OllamaMemoryProbe implements MemoryProbe
{
    private const BYTES_PER_KIB = 1024;

    public function __construct(
        private readonly HostInspector $host,
        private readonly OllamaClient $server,
        private readonly string $model = '',
    ) {}

    public function read(): MemorySample
    {
        $gpu = 0;

        $spilled = 0;

        $models = $this->server->running();

        foreach ($models as $model) {
            $size = (int) ($model['size'] ?? 0);

            $inGpu = (int) ($model['size_vram'] ?? 0);

            $gpu += $inGpu;

            $spilled += max(0, $size - $inGpu);
        }

        return new MemorySample(
            at: microtime(true),
            residentBytes: $this->isMacOs()
                ? $this->wiredBytes()
                : $this->ollamaResidentBytes(),
            gpuBytes: $gpu,
            spilledBytes: $spilled,
            modelLoaded: $this->isLoaded($models),
        );
    }

    public function residentSource(): string
    {
        return $this->isMacOs()
            ? 'machine-wide wired memory from vm_stat (includes everything else wired, not only the model)'
            : 'resident set size of the ollama processes from ps';
    }

    private function isMacOs(): bool
    {
        return $this->host->operatingSystem() === 'Darwin';
    }

    private function wiredBytes(): int
    {
        $output = $this->host->run(['vm_stat']);

        preg_match('/page size of (\d+) bytes/', $output, $size);

        preg_match('/Pages wired down:\s+(\d+)/', $output, $wired);

        return (int) ($size[1] ?? 0) * (int) ($wired[1] ?? 0);
    }

    private function isLoaded(array $models): bool
    {
        if ($this->model === '') {
            return $models !== [];
        }

        foreach ($models as $model) {
            if (($model['name'] ?? '') === $this->model) {
                return true;
            }

            if (($model['model'] ?? '') === $this->model) {
                return true;
            }
        }

        return false;
    }

    private function ollamaResidentBytes(): int
    {
        $kibibytes = 0;

        foreach (explode("\n", $this->host->run(['ps', '-axo', 'rss=,comm='])) as $line) {
            if (! preg_match('/^\s*(\d+)\s+(.+)$/', $line, $parts)) {
                continue;
            }

            if (! str_contains(strtolower(basename($parts[2])), 'ollama')) {
                continue;
            }

            $kibibytes += (int) $parts[1];
        }

        return $kibibytes * self::BYTES_PER_KIB;
    }
}
