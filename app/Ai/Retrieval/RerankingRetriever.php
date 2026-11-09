<?php

namespace App\Ai\Retrieval;

use App\Ai\Contracts\Reranker;
use App\Ai\Exceptions\SidecarFailed;

final class RerankingRetriever implements ConfigurableRetriever
{
    public function __construct(
        private ConfigurableRetriever $inner,
        private readonly Reranker $reranker,
        private int $candidates = 20,
    ) {}

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $candidates = $this->inner->retrieve($question, $scope, max($this->candidates, $k));

        if (count($candidates) <= $k) {
            return $candidates;
        }

        $scores = $this->reranker->score($question, array_map(
            fn (RetrievedChunk $candidate): string => $candidate->matchText(),
            $candidates,
        ));

        if (count($scores) !== count($candidates)) {
            throw SidecarFailed::mismatchedScores(count($candidates), count($scores));
        }

        return collect($candidates)
            ->zip($scores)
            ->sortByDesc(fn ($pair): float => (float) $pair[1])
            ->take($k)
            ->map(fn ($pair): RetrievedChunk => $pair[0])
            ->values()
            ->all();
    }

    public function using(RetrievalSettings $settings): static
    {
        $clone = clone $this;

        $clone->inner = $this->inner->using($settings->with(candidatePool: max($settings->candidatePool, $this->candidates)));

        return $clone;
    }

    public function withCandidates(int $candidates): static
    {
        $clone = clone $this;

        $clone->candidates = $candidates;

        return $clone->using($this->inner->settings());
    }

    public function settings(): RetrievalSettings
    {
        return $this->inner->settings();
    }
}
