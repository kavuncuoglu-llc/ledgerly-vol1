<?php

namespace App\Ai\Retrieval;

use App\Ai\AiManager;
use App\Ai\Contracts\Reranker;
use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\UnsupportedReranker;

final class RetrieverFactory
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly AiManager $ai,
    ) {}

    public function make(
        ?RetrievalSettings $settings = null,
        ?RewriteMode $rewrite = null,
        ?string $reranker = null,
        ?int $candidates = null,
        ?Embedder $embedder = null,
    ): ConfigurableRetriever {
        $retriever = $this->withRewriting(
            new PgRetriever($embedder ?? $this->embedder, $settings ?? RetrievalSettings::fromConfig()),
            $rewrite ?? RewriteMode::from((string) config('ai.retrieval.rewrite')),
        );

        return $this->withReranking(
            $retriever,
            $reranker ?? (string) config('ai.retrieval.reranker'),
            $candidates ?? (int) config('ai.retrieval.rerank_candidates'),
        );
    }

    public function rerankerFor(string $driver): ?Reranker
    {
        return match ($driver) {
            'none' => null,
            'sidecar' => $this->ai->reranker(),
            'lexical' => $this->ai->reranker('rerank-lexical'),
            default => throw new UnsupportedReranker($driver),
        };
    }

    private function withRewriting(ConfigurableRetriever $retriever, RewriteMode $mode): ConfigurableRetriever
    {
        if ($mode === RewriteMode::Off) {
            return $retriever;
        }

        return new QueryRewritingRetriever(
            inner: $retriever,
            rewriter: new QueryRewriter($this->ai, $mode, config('ai.retrieval.rewrite_model')),
        );
    }

    private function withReranking(ConfigurableRetriever $retriever, string $driver, int $candidates): ConfigurableRetriever
    {
        $reranker = $this->rerankerFor($driver);

        if ($reranker === null) {
            return $retriever;
        }

        return (new RerankingRetriever($retriever, $reranker, $candidates))->using($retriever->settings());
    }
}
