<?php

namespace App\Ai\Compare;

final readonly class Score
{
    public function __construct(
        public string $scorer,
        public float $value,
        public string $detail = '',
    ) {}

    public function toArray(): array
    {
        return [
            'scorer' => $this->scorer,
            'value' => round($this->value, 4),
            'detail' => $this->detail,
        ];
    }
}
