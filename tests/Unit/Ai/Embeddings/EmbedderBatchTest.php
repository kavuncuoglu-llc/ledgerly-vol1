<?php

namespace Tests\Unit\Ai\Embeddings;

use App\Ai\AiManager;
use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\EmbeddingFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class EmbedderBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_documents_sends_every_text_in_one_request_with_the_document_prefix(): void
    {
        Http::fake(['*' => Http::response($this->body([[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]]))]);

        $this->embedder()->documents(['one', 'two', 'three']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['input'] === [
            'search_document: one',
            'search_document: two',
            'search_document: three',
        ]);
    }

    public function test_documents_returns_the_vectors_in_input_order_even_when_the_server_reorders_them(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [
                ['index' => 1, 'embedding' => [0.3, 0.4]],
                ['index' => 0, 'embedding' => [0.1, 0.2]],
            ],
        ])]);

        $vectors = $this->embedder()->documents(['one', 'two']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $vectors);
    }

    public function test_documents_with_a_single_text_uses_the_single_input_request(): void
    {
        Http::fake(['*' => Http::response($this->body([[0.1, 0.2]]))]);

        $this->embedder()->documents(['only']);

        Http::assertSent(fn (Request $request): bool => $request['input'] === 'search_document: only');
    }

    public function test_documents_rejects_a_reply_with_fewer_vectors_than_texts(): void
    {
        Http::fake(['*' => Http::response($this->body([[0.1, 0.2]]))]);

        $this->expectException(EmbeddingFailed::class);

        $this->embedder()->documents(['one', 'two']);
    }

    public function test_documents_rejects_a_vector_of_the_wrong_size(): void
    {
        Http::fake(['*' => Http::response($this->body([[0.1, 0.2], [0.3]]))]);

        $this->expectException(EmbeddingFailed::class);

        $this->embedder()->documents(['one', 'two']);
    }

    private function body(array $vectors): array
    {
        return [
            'data' => array_map(
                fn (array $vector, int $index): array => ['index' => $index, 'embedding' => $vector],
                $vectors,
                array_keys($vectors),
            ),
        ];
    }

    private function embedder(): Embedder
    {
        config()->set('ai.providers.local-embed.base_url', 'http://model.test/v1');

        return new Embedder(app(AiManager::class)->embedUsing('nomic-embed-text', 2));
    }
}
