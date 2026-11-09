<?php

namespace Tests\Feature\Ai\Retrieval;

use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\RetrievalMode;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class PgRetrieverTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    private const QUESTION = 'What late fee does Ledgerly charge?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableVectorIndexScans();
    }

    public function test_retriever_interface_resolves_to_the_pgvector_implementation(): void
    {
        $this->assertInstanceOf(PgRetriever::class, app(Retriever::class));
    }

    public function test_retrieve_returns_chunks_above_the_floor_best_first(): void
    {
        $this->seedChunk('late-fees', 'How the fee is calculated', 'The fee is 1.5%.', $this->blendedVector(0, 1, 0.95));
        $this->seedChunk('reminders', 'Schedule', 'Reminders go out.', $this->blendedVector(0, 1, 0.80), position: 2);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope);

        $this->assertSame(['late-fees › How the fee is calculated', 'reminders › Schedule'], array_map(fn ($chunk) => $chunk->label(), $chunks));
        $this->assertEqualsWithDelta(0.95, $chunks[0]->similarity, 0.001);
    }

    public function test_retrieve_returns_nothing_when_every_chunk_is_below_the_floor(): void
    {
        $this->seedChunk('late-fees', 'How the fee is calculated', 'The fee is 1.5%.', $this->blendedVector(0, 1, 0.31));
        $this->fakeEmbeddingsAndChat(['best pizza near me' => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve('best pizza near me', new RetrievalScope);

        $this->assertSame([], $chunks);
    }

    public function test_retrieve_returns_nothing_when_nothing_has_been_ingested(): void
    {
        Http::fake();

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope);

        $this->assertSame([], $chunks);
        Http::assertNothingSent();
    }

    public function test_retrieve_keeps_a_keyword_match_that_the_vector_side_scored_below_the_floor(): void
    {
        $this->seedChunk('statuses', 'Overdue', 'Invoice 4471 is overdue after the due date.', $this->blendedVector(0, 1, 0.20));
        $this->fakeEmbeddingsAndChat(['status 4471' => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve('status 4471', new RetrievalScope);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->keywordMatched);
    }

    public function test_retrieve_honours_the_cap(): void
    {
        foreach (range(1, 4) as $position) {
            $this->seedChunk("article-{$position}", 'Section', 'Text.', $this->blendedVector(0, $position, 0.9), position: $position);
        }
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope, k: 2);

        $this->assertCount(2, $chunks);
    }

    public function test_retrieve_pre_filters_out_chunks_tagged_for_other_plans(): void
    {
        $this->seedChunk('enterprise-terms', 'Terms', 'Custom terms.', $this->axisVector(0), tags: ['area' => 'billing', 'plans' => ['business']]);
        $this->seedChunk('late-fees', 'Overview', 'Fees.', $this->blendedVector(0, 1, 0.9), position: 2);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope(plan: 'free'));

        $this->assertSame(['late-fees › Overview'], array_map(fn ($chunk) => $chunk->label(), $chunks));
    }

    public function test_retrieve_keeps_chunks_tagged_for_the_customers_plan(): void
    {
        $this->seedChunk('enterprise-terms', 'Terms', 'Custom terms.', $this->axisVector(0), tags: ['area' => 'billing', 'plans' => ['business', 'pro']]);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope(plan: 'pro'));

        $this->assertCount(1, $chunks);
    }

    public function test_retrieve_narrows_to_the_area_when_one_is_given(): void
    {
        $this->seedChunk('profile', 'Name', 'Your profile.', $this->axisVector(0), category: 'account');
        $this->seedChunk('late-fees', 'Overview', 'Fees.', $this->axisVector(0), position: 2);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope(area: 'account'));

        $this->assertSame(['profile › Name'], array_map(fn ($chunk) => $chunk->label(), $chunks));
    }

    public function test_retrieve_only_searches_the_latest_ingested_version(): void
    {
        $this->seedChunk('late-fees', 'Old', 'Old text.', $this->axisVector(0), version: 'old');
        $this->travel(1)->minute();
        $this->seedChunk('late-fees', 'New', 'New text.', $this->axisVector(0), version: 'new', position: 2);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope);

        $this->assertSame(['late-fees › New'], array_map(fn ($chunk) => $chunk->label(), $chunks));
        $this->assertSame('new', $chunks[0]->helpVersion);
    }

    public function test_vector_mode_ignores_keyword_matches_below_the_floor(): void
    {
        $this->seedChunk('statuses', 'Overdue', 'Invoice 4471 is overdue.', $this->blendedVector(0, 1, 0.20));
        $this->fakeEmbeddingsAndChat(['status 4471' => $this->axisVector(0)]);
        $retriever = app(Retriever::class);

        $chunks = $retriever->using($retriever->settings()->with(mode: RetrievalMode::Vector))->retrieve('status 4471', new RetrievalScope);

        $this->assertSame([], $chunks);
    }

    public function test_keyword_mode_finds_exact_terms_without_calling_the_embedding_model(): void
    {
        $this->seedChunk('statuses', 'Overdue', 'Invoice 4471 is overdue.', $this->axisVector(5));
        Http::fake();
        $retriever = app(Retriever::class);

        $chunks = $retriever->using($retriever->settings()->with(mode: RetrievalMode::Keyword))->retrieve('invoice 4471', new RetrievalScope);

        $this->assertCount(1, $chunks);
        Http::assertNothingSent();
    }
}
