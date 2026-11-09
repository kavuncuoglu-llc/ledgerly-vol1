<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;

final class FakeEmbeddings implements EmbeddingModel
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'fake-embed');
    }

    public function dimensions(): int
    {
        return (int) ($this->config['dimensions'] ?? 8);
    }

    public function embed(array $texts, EmbedKind $kind): array
    {
        return array_map(fn (string $text): array => $this->vectorOf($text), array_values($texts));
    }

    private function vectorOf(string $text): array
    {
        $vector = [];

        for ($position = 0; $position < $this->dimensions(); $position++) {
            $vector[] = (hexdec(substr(hash('sha256', $text.$position), 0, 6)) % 2000) / 1000 - 1;
        }

        return $vector;
    }
}
