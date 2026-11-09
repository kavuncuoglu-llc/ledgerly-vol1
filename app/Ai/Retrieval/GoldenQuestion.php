<?php

namespace App\Ai\Retrieval;

final readonly class GoldenQuestion
{
    public function __construct(
        public int $id,
        public string $question,
        public array $chunks,
    ) {}

    public function expectsNothing(): bool
    {
        return $this->chunks === [];
    }
}
