<?php

namespace App\Ai\Retrieval;

final class QueryRewritingRetriever implements ConfigurableRetriever
{
    public function __construct(
        private ConfigurableRetriever $inner,
        private readonly QueryRewriter $rewriter,
    ) {}

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $queries = $this->rewriter->queries($question);

        if (count($queries) === 1) {
            return $this->inner->retrieve($question, $scope, $k);
        }

        $lists = array_map(
            fn (string $query): array => $this->inner->retrieve($query, $scope, $k),
            $queries,
        );

        return ReciprocalRankFusion::merge($lists, $this->inner->settings()->rrfConstant, $k);
    }

    public function using(RetrievalSettings $settings): static
    {
        $clone = clone $this;

        $clone->inner = $this->inner->using($settings);

        return $clone;
    }

    public function settings(): RetrievalSettings
    {
        return $this->inner->settings();
    }
}
