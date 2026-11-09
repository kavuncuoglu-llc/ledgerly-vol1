<?php

namespace App\Ai\Retrieval;

final readonly class RetrievalSettings
{
    public function __construct(
        public RetrievalMode $mode,
        public float $similarityFloor,
        public int $k,
        public int $rrfConstant,
        public int $candidatePool,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            mode: RetrievalMode::from((string) config('ai.retrieval.mode')),
            similarityFloor: (float) config('ai.retrieval.similarity_floor'),
            k: (int) config('ai.retrieval.k'),
            rrfConstant: (int) config('ai.retrieval.rrf_constant'),
            candidatePool: (int) config('ai.retrieval.candidate_pool'),
        );
    }

    public function with(?RetrievalMode $mode = null, ?float $similarityFloor = null, ?int $k = null): self
    {
        return new self(
            mode: $mode ?? $this->mode,
            similarityFloor: $similarityFloor ?? $this->similarityFloor,
            k: $k ?? $this->k,
            rrfConstant: $this->rrfConstant,
            candidatePool: max($this->candidatePool, $k ?? $this->k),
        );
    }
}
