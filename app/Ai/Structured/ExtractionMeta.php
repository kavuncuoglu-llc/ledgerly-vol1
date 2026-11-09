<?php

namespace App\Ai\Structured;

final readonly class ExtractionMeta
{
    public function __construct(
        public bool $repaired = false,
        public int $attempts = 1,
    ) {}
}
