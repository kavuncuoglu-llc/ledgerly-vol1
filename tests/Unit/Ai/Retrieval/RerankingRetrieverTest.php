<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Exceptions\SidecarFailed;
use App\Ai\Retrieval\ConfigurableRetriever;
use App\Ai\Retrieval\Reranker;
use App\Ai\Retrieval\RerankingRetriever;
use App\Ai\Retrieval\RetrievalMode;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrievalSettings;
use App\Ai\Retrieval\RetrievedChunk;
use PHPUnit\Framework\TestCase;

class RerankingRetrieverTest extends TestCase
{
    public function test_retrieve_asks_the_inner_retriever_for_the_candidate_pool_not_k(): void
    {
        $inner = new RecordingRetriever($this->chunks(8));

        $retriever = new RerankingRetriever($inner, new ScoresByPosition([]), candidates: 20);

        $retriever->retrieve('question', new RetrievalScope, k: 5);

        $this->assertSame(20, $inner->requestedK);
    }

    public function test_retrieve_returns_the_top_k_by_reranker_score(): void
    {
        $inner = new RecordingRetriever($this->chunks(8));

        $retriever = new RerankingRetriever($inner, new ScoresByPosition([6 => 0.9, 7 => 0.8, 0 => 0.5]), candidates: 20);

        $result = $retriever->retrieve('question', new RetrievalScope, k: 3);

        $this->assertSame([7, 8, 1], array_map(fn (RetrievedChunk $chunk): int => $chunk->id, $result));
    }

    public function test_retrieve_keeps_stage_one_order_for_equal_scores(): void
    {
        $inner = new RecordingRetriever($this->chunks(6));

        $retriever = new RerankingRetriever($inner, new ScoresByPosition([]), candidates: 20);

        $result = $retriever->retrieve('question', new RetrievalScope, k: 3);

        $this->assertSame([1, 2, 3], array_map(fn (RetrievedChunk $chunk): int => $chunk->id, $result));
    }

    public function test_retrieve_skips_the_reranker_when_there_is_nothing_to_cut(): void
    {
        $reranker = new ScoresByPosition([]);

        $retriever = new RerankingRetriever(new RecordingRetriever($this->chunks(3)), $reranker, candidates: 20);

        $result = $retriever->retrieve('question', new RetrievalScope, k: 5);

        $this->assertCount(3, $result);
        $this->assertSame(0, $reranker->calls);
    }

    public function test_retrieve_scores_the_matched_text_of_each_candidate(): void
    {
        $chunks = [
            $this->chunk(1, 'parent one', 'child header one'),
            $this->chunk(2, 'parent two', ''),
        ];

        $reranker = new ScoresByPosition([]);

        (new RerankingRetriever(new RecordingRetriever($chunks), $reranker, candidates: 20))->retrieve('question', new RetrievalScope, k: 1);

        $this->assertSame(['child header one', 'parent two'], $reranker->passages);
        $this->assertSame('question', $reranker->question);
    }

    public function test_retrieve_fails_when_the_reranker_returns_the_wrong_number_of_scores(): void
    {
        $reranker = new class implements Reranker
        {
            public function score(string $question, array $passages): array
            {
                return [0.1];
            }
        };

        $retriever = new RerankingRetriever(new RecordingRetriever($this->chunks(8)), $reranker, candidates: 20);

        $this->expectException(SidecarFailed::class);

        $retriever->retrieve('question', new RetrievalScope, k: 3);
    }

    public function test_using_widens_the_inner_candidate_pool_to_the_candidate_count(): void
    {
        $inner = new RecordingRetriever([]);

        $retriever = new RerankingRetriever($inner, new ScoresByPosition([]), candidates: 40);

        $retriever->using(new RetrievalSettings(RetrievalMode::Hybrid, 0.7, 5, 60, 20));

        $this->assertSame(40, $inner->lastSettings->candidatePool);
    }

    private function chunks(int $count): array
    {
        return array_map(fn (int $id): RetrievedChunk => $this->chunk($id, "content {$id}", ''), range(1, $count));
    }

    private function chunk(int $id, string $content, string $matchedText): RetrievedChunk
    {
        return new RetrievedChunk(
            id: $id,
            source: 'help/billing/late-fees.md',
            title: 'Late fees',
            headingPath: 'Billing › Late fees › Section',
            content: $content,
            matchedText: $matchedText,
        );
    }
}

class RecordingRetriever implements ConfigurableRetriever
{
    public int $requestedK = 0;

    public ?RetrievalSettings $lastSettings = null;

    public function __construct(private readonly array $chunks) {}

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $this->requestedK = $k;

        return $this->chunks;
    }

    public function using(RetrievalSettings $settings): static
    {
        $this->lastSettings = $settings;

        return $this;
    }

    public function settings(): RetrievalSettings
    {
        return $this->lastSettings ?? new RetrievalSettings(RetrievalMode::Hybrid, 0.7, 5, 60, 20);
    }
}

class ScoresByPosition implements Reranker
{
    public int $calls = 0;

    public array $passages = [];

    public string $question = '';

    public function __construct(private readonly array $scores) {}

    public function score(string $question, array $passages): array
    {
        $this->calls++;
        $this->passages = $passages;
        $this->question = $question;

        return array_map(fn (int $position): float => $this->scores[$position] ?? 0.0, array_keys($passages));
    }
}
