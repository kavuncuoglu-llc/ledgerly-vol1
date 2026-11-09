<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\ReciprocalRankFusion;
use App\Ai\Retrieval\RetrievedChunk;
use PHPUnit\Framework\TestCase;

class ReciprocalRankFusionTest extends TestCase
{
    public function test_merge_ranks_a_chunk_found_by_two_lists_above_chunks_found_by_one(): void
    {
        $merged = ReciprocalRankFusion::merge([
            [$this->chunk(1), $this->chunk(2)],
            [$this->chunk(3), $this->chunk(2)],
        ], constant: 60, limit: 5);

        $this->assertSame([2, 1, 3], array_map(fn (RetrievedChunk $chunk): int => $chunk->id, $merged));
    }

    public function test_merge_sums_reciprocal_ranks(): void
    {
        $merged = ReciprocalRankFusion::merge([
            [$this->chunk(1)],
            [$this->chunk(1)],
        ], constant: 60, limit: 5);

        $this->assertEqualsWithDelta(2 / 61, $merged[0]->rrf, 0.00001);
    }

    public function test_merge_keeps_the_best_similarity_and_any_keyword_match(): void
    {
        $merged = ReciprocalRankFusion::merge([
            [$this->chunk(1, similarity: 0.60)],
            [$this->chunk(1, similarity: 0.80, keyword: true)],
        ], constant: 60, limit: 5);

        $this->assertSame(0.80, $merged[0]->similarity);
        $this->assertTrue($merged[0]->keywordMatched);
    }

    public function test_merge_honours_the_limit(): void
    {
        $merged = ReciprocalRankFusion::merge([
            [$this->chunk(1), $this->chunk(2), $this->chunk(3)],
        ], constant: 60, limit: 2);

        $this->assertCount(2, $merged);
    }

    private function chunk(int $id, float $similarity = 0.0, bool $keyword = false): RetrievedChunk
    {
        return new RetrievedChunk(
            id: $id,
            source: 'help/billing/a.md',
            title: 'A',
            headingPath: 'Billing › A › B',
            content: 'content',
            similarity: $similarity,
            keywordMatched: $keyword,
        );
    }
}
