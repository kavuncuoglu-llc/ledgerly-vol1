<?php

namespace App\Ai\Retrieval;

final readonly class CitationReport
{
    public function __construct(
        public array $errors = [],
        public array $cited = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }
}
