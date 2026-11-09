<?php

namespace App\Ai\Context;

final readonly class AssembledPrompt
{
    public function __construct(
        public array $messages,
        public array $breakdown,
        public array $overBudget = [],
        public array $labelMap = [],
        public array $breakpoints = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->breakdown);
    }
}
