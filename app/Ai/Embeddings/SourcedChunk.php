<?php

namespace App\Ai\Embeddings;

use Carbon\CarbonImmutable;

final class SourcedChunk
{
    public function __construct(
        public readonly string $source,
        public readonly int $position,
        public readonly Chunk $chunk,
        public readonly string $title = '',
        public readonly string $headingPath = '',
        public readonly array $tags = [],
        public readonly ?CarbonImmutable $sourceModifiedAt = null,
        public readonly string $articleBody = '',
    ) {}

    public function chunkKey(): string
    {
        return sha1($this->source.$this->headingPath.$this->position);
    }

    public function fingerprint(): string
    {
        return sha1(json_encode([
            $this->chunk->content,
            $this->title,
            $this->headingPath,
            $this->tags,
        ]));
    }
}
