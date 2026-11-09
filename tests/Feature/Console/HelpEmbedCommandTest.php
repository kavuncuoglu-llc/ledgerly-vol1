<?php

namespace Tests\Feature\Console;

use App\Models\HelpChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HelpEmbedCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_embeds_every_help_center_chunk_with_the_document_prefix(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody())]);

        $this->artisan('help:embed')
            ->expectsOutputToContain('Chunking 120 articles...')
            ->expectsOutput('Embedding with nomic-embed-text...')
            ->expectsOutput('Stored in help_chunks.')
            ->assertSuccessful();

        $storedCount = HelpChunk::query()->count();

        $this->assertGreaterThan(120, $storedCount);

        Http::assertSentCount($storedCount);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request['input'], 'search_document: '));
    }

    public function test_handle_stores_source_position_heading_content_and_tokens(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody())]);

        $this->artisan('help:embed')->assertSuccessful();

        $chunk = HelpChunk::query()
            ->where('source', 'help/billing/late-fees.md')
            ->orderBy('position')
            ->firstOrFail();

        $this->assertSame(1, $chunk->position);
        $this->assertStringStartsWith('Understanding late fees › ', $chunk->heading);
        $this->assertStringStartsWith($chunk->heading, $chunk->content);
        $this->assertGreaterThan(0, $chunk->tokens);
        $this->assertCount(768, $chunk->embedding);
    }

    public function test_handle_is_idempotent_when_run_twice(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody())]);

        $this->artisan('help:embed')->assertSuccessful();
        $firstCount = HelpChunk::query()->count();

        $this->artisan('help:embed')->assertSuccessful();

        $this->assertSame($firstCount, HelpChunk::query()->count());
    }

    public function test_handle_removes_chunks_of_articles_that_no_longer_exist(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody())]);
        HelpChunk::query()->create([
            'source' => 'help/billing/removed.md',
            'position' => 1,
            'heading' => 'Removed',
            'content' => 'Removed',
            'tokens' => 1,
            'embedding' => array_fill(0, 768, 0.1),
        ]);

        $this->artisan('help:embed')->assertSuccessful();

        $this->assertFalse(HelpChunk::query()->where('source', 'help/billing/removed.md')->exists());
    }

    public function test_handle_fails_with_a_message_when_the_model_server_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->artisan('help:embed')
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();

        $this->assertSame(0, HelpChunk::query()->count());
    }

    public function test_handle_fails_when_the_model_returns_the_wrong_dimension(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [
                [
                    'embedding' => [0.1, 0.2],
                ],
            ],
        ])]);

        $this->artisan('help:embed')
            ->expectsOutputToContain('returned 2 dimensions')
            ->assertFailed();
    }

    private function embeddingBody(): array
    {
        return [
            'data' => [
                [
                    'embedding' => array_fill(0, 768, 0.1),
                ],
            ],
        ];
    }
}
