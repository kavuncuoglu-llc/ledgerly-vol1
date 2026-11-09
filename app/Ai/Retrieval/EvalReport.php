<?php

namespace App\Ai\Retrieval;

final readonly class EvalReport
{
    public function __construct(
        public array $recall,
        public array $precision,
        public float $mrr,
        public int $emptyCorrect,
        public int $emptyTotal,
        public array $misses,
        public int $questions,
        public float $queryMs = 0.0,
    ) {}

    public function toArray(): array
    {
        return [
            'recall' => $this->recall,
            'precision' => $this->precision,
            'mrr' => $this->mrr,
            'empty_correct' => $this->emptyCorrect,
            'empty_total' => $this->emptyTotal,
            'misses' => array_column($this->misses, 'id'),
            'questions' => $this->questions,
            'query_ms' => round($this->queryMs, 1),
        ];
    }
}
