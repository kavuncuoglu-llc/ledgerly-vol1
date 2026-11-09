<?php

namespace App\Ai\Embeddings;

final readonly class ChildChunk
{
    public function __construct(
        public int $index,
        public string $text,
        public int $tokens,
    ) {}
}
