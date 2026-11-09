<?php

namespace App\Ai\Embeddings;

use Illuminate\Support\Collection;

final readonly class ParentChildPlan
{
    public function __construct(
        public Collection $parents,
        public Collection $added,
        public Collection $changed,
        public Collection $unchanged,
        public array $removedKeys,
    ) {}

    public function toEmbed(): Collection
    {
        return $this->added->concat($this->changed)->values();
    }

    public function childCount(): int
    {
        return $this->added->count() + $this->changed->count() + $this->unchanged->count();
    }

    public function averageChildTokens(): int
    {
        $units = $this->added->concat($this->changed)->concat($this->unchanged);

        return (int) round($units->avg(fn (ChildUnit $unit): int => $unit->child->tokens));
    }
}
