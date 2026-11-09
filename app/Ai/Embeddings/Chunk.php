<?php

namespace App\Ai\Embeddings;

final class Chunk
{
    public function __construct(
        public readonly string $heading,
        public readonly string $content,
        public readonly int $tokens,
    ) {}
}
