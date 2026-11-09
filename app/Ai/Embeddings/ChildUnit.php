<?php

namespace App\Ai\Embeddings;

final readonly class ChildUnit
{
    public const POSITION_STRIDE = 1000;

    public const HEADER_SEPARATOR = ' — ';

    public function __construct(
        public SourcedChunk $parent,
        public ChildChunk $child,
        public ?string $reusableHeader,
    ) {}

    public function chunkKey(): string
    {
        return sha1($this->parent->chunkKey().':c'.$this->child->index);
    }

    public function position(): int
    {
        return $this->parent->position * self::POSITION_STRIDE + $this->child->index + 1;
    }

    public function baseHeader(): string
    {
        return $this->parent->chunk->heading;
    }

    public function withHeader(string $header): string
    {
        return $header."\n\n".$this->child->text;
    }
}
