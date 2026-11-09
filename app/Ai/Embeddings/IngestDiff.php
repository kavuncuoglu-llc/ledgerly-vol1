<?php

namespace App\Ai\Embeddings;

use Illuminate\Support\Collection;

final readonly class IngestDiff
{
    public function __construct(
        public Collection $added,
        public Collection $changed,
        public Collection $unchanged,
        public array $removedKeys,
    ) {}

    public function toEmbed(): Collection
    {
        return $this->added->concat($this->changed)->values();
    }

    public function removedCount(): int
    {
        return count($this->removedKeys);
    }
}
