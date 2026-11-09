<?php

namespace App\Ai\Hardware;

final readonly class SizingAlternative
{
    public function __construct(
        public string $change,
        public int $totalBytes,
        public bool $fits,
    ) {}
}
