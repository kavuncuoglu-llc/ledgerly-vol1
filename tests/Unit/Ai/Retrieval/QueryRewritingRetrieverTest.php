<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Http\AiHttpClient;
use App\Ai\Retrieval\ConfigurableRetriever;
use App\Ai\Retrieval\QueryRewriter;
use App\Ai\Retrieval\QueryRewritingRetriever;
use App\Ai\Retrieval\RetrievalMode;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrievalSettings;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Retrieval\RewriteMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class QueryRewritingRetrieverTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    public function test_retrieve_merges_the_original_and_the_rewrite_with_rank_fusion(): void
    {
        Http::fake(['*' => Http::response($this->chatReply('reminders'))]);
        $inner = new MapRetriever([
            'my client pays late' => [$this->chunk(1), $this->chunk(2)],
            'reminders' => [$this->chunk(3), $this->chunk(2)],
        ]);

        $result = $this->retriever($inner)->retrieve('my client pays late', new RetrievalScope, k: 5);

        $this->assertSame([2, 1, 3], array_map(fn (RetrievedChunk $chunk): int => $chunk->id, $result));
        $this->assertSame(['my client pays late', 'reminders'], $inner->queries);
    }

    public function test_retrieve_passes_identifier_questions_through_verbatim(): void
    {
        Http::fake();
        $inner = new MapRetriever([
            'status of invoice 4471' => [$this->chunk(1)],
        ]);

        $result = $this->retriever($inner)->retrieve('status of invoice 4471', new RetrievalScope, k: 5);

        $this->assertCount(1, $result);
        $this->assertSame(['status of invoice 4471'], $inner->queries);
        Http::assertNothingSent();
    }

    public function test_retrieve_can_rescue_a_question_whose_own_retrieval_is_empty(): void
    {
        Http::fake(['*' => Http::response($this->chatReply('reminders'))]);
        $inner = new MapRetriever([
            'my client pays late' => [],
            'reminders' => [$this->chunk(3)],
        ]);

        $result = $this->retriever($inner)->retrieve('my client pays late', new RetrievalScope, k: 5);

        $this->assertSame([3], array_map(fn (RetrievedChunk $chunk): int => $chunk->id, $result));
    }

    private function retriever(MapRetriever $inner): QueryRewritingRetriever
    {
        return new QueryRewritingRetriever($inner, new QueryRewriter(app(AiHttpClient::class), RewriteMode::Rewrite));
    }

    private function chunk(int $id): RetrievedChunk
    {
        return new RetrievedChunk($id, 'help/billing/a.md', 'A', 'Billing › A › B', 'content');
    }
}

class MapRetriever implements ConfigurableRetriever
{
    public array $queries = [];

    public function __construct(private readonly array $byQuery) {}

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $this->queries[] = $question;

        return $this->byQuery[$question] ?? [];
    }

    public function using(RetrievalSettings $settings): static
    {
        return $this;
    }

    public function settings(): RetrievalSettings
    {
        return new RetrievalSettings(RetrievalMode::Hybrid, 0.7, 5, 60, 20);
    }
}
