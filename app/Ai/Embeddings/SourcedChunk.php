<?php

namespace App\Ai\Embeddings;

final class SourcedChunk
{
    public function __construct(
        public readonly string $source,
        public readonly int $position,
        public readonly Chunk $chunk,
    ) {}
}
