<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Http\RetryPolicy;

final class RetryingEmbeddingModel implements EmbeddingModel
{
    public function __construct(
        private readonly EmbeddingModel $inner,
        private readonly RetryPolicy $policy = new RetryPolicy,
    ) {}

    public function embed(array $texts, EmbedKind $kind): array
    {
        return $this->policy->run(fn (): array => $this->inner->embed($texts, $kind));
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    public function name(): string
    {
        return $this->inner->name();
    }
}
