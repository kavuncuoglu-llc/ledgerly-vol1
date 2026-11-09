<?php

namespace Tests\Unit\Ai\Embeddings;

use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Http\AiHttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class EmbedderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_query_prefixes_the_text_with_search_query(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody([0.1, 0.2, 0.3]))]);

        $this->embedder(dimensions: 3)->query('my invoice is overdue');

        Http::assertSent(fn (Request $request): bool => $request['input'] === 'search_query: my invoice is overdue');
    }

    public function test_document_prefixes_the_text_with_search_document(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody([0.1, 0.2, 0.3]))]);

        $this->embedder(dimensions: 3)->document('Late fees are applied');

        Http::assertSent(fn (Request $request): bool => $request['input'] === 'search_document: Late fees are applied');
    }

    public function test_embed_posts_the_configured_model_to_the_embeddings_endpoint(): void
    {
        config()->set('ai.base_url', 'http://model.test/v1');
        Http::fake(['*' => Http::response($this->embeddingBody([0.1, 0.2, 0.3]))]);

        $this->embedder(dimensions: 3, model: 'other-embedder')->query('hello');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://model.test/v1/embeddings'
            && $request['model'] === 'other-embedder');
    }

    public function test_query_returns_the_vector_as_floats(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody([1, 0.5, 0]))]);

        $vector = $this->embedder(dimensions: 3)->query('hello');

        $this->assertSame([1.0, 0.5, 0.0], $vector);
    }

    public function test_query_throws_when_the_vector_has_the_wrong_number_of_dimensions(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody([0.1, 0.2]))]);

        $this->expectException(EmbeddingFailed::class);
        $this->expectExceptionMessage('returned 2 dimensions');

        $this->embedder(dimensions: 3)->query('hello');
    }

    public function test_query_throws_when_the_body_has_no_embedding(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->expectException(EmbeddingFailed::class);

        $this->embedder(dimensions: 3)->query('hello');
    }

    public function test_query_throws_when_the_server_rejects_the_request(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $this->expectException(ModelRequestFailed::class);

        $this->embedder(dimensions: 3)->query('hello');
    }

    private function embedder(int $dimensions, string $model = 'nomic-embed-text'): Embedder
    {
        return new Embedder(
            client: new AiHttpClient(config('ai')),
            model: $model,
            dimensions: $dimensions,
        );
    }

    private function embeddingBody(array $vector): array
    {
        return [
            'data' => [
                [
                    'embedding' => $vector,
                ],
            ],
        ];
    }
}
