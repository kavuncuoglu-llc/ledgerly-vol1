<?php

namespace App\Ai\Retrieval;

use App\Ai\Exceptions\InvalidIndexTable;

final readonly class RetrievalSettings
{
    public const DEFAULT_TABLE = 'help_chunks';

    public function __construct(
        public RetrievalMode $mode,
        public float $similarityFloor,
        public int $k,
        public int $rrfConstant,
        public int $candidatePool,
        public string $table = self::DEFAULT_TABLE,
        public int $dimensions = 768,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $table) !== 1) {
            throw InvalidIndexTable::name($table);
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            mode: RetrievalMode::from((string) config('ai.retrieval.mode')),
            similarityFloor: (float) config('ai.retrieval.similarity_floor'),
            k: (int) config('ai.retrieval.k'),
            rrfConstant: (int) config('ai.retrieval.rrf_constant'),
            candidatePool: (int) config('ai.retrieval.candidate_pool'),
            dimensions: (int) config('ai.embed_dimensions'),
        );
    }

    public function with(
        ?RetrievalMode $mode = null,
        ?float $similarityFloor = null,
        ?int $k = null,
        ?int $candidatePool = null,
        ?string $table = null,
        ?int $dimensions = null,
    ): self {
        return new self(
            mode: $mode ?? $this->mode,
            similarityFloor: $similarityFloor ?? $this->similarityFloor,
            k: $k ?? $this->k,
            rrfConstant: $this->rrfConstant,
            candidatePool: max($candidatePool ?? $this->candidatePool, $k ?? $this->k),
            table: $table ?? $this->table,
            dimensions: $dimensions ?? $this->dimensions,
        );
    }
}
