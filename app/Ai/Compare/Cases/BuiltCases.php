<?php

namespace App\Ai\Compare\Cases;

final readonly class BuiltCases
{
    public function __construct(
        public array $cases,
        public array $skipped = [],
    ) {}
}
