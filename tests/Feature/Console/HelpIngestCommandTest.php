<?php

namespace Tests\Feature\Console;

use App\Ai\Cache\HelpVersion;
use App\Models\HelpChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class HelpIngestCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_ingests_every_article_with_metadata_and_the_given_version(): void
    {
        $this->fakeEmbeddings();

        $this->artisan('help:ingest', ['--docs-version' => '7b2e9d0'])
            ->expectsOutputToContain('Stripping boilerplate (4 patterns)')
            ->expectsOutputToContain('Chunking 120 articles')
            ->expectsOutputToContain('Diff vs an empty index')
            ->expectsOutput('Upserted. help_version = 7b2e9d0')
            ->assertSuccessful();

        $chunk = HelpChunk::query()
            ->where('source', 'help/billing/late-fees.md')
            ->where('heading_path', 'Billing › Understanding late fees › How the fee is calculated')
            ->firstOrFail();

        $this->assertSame('Understanding late fees', $chunk->title);
        $this->assertSame('7b2e9d0', $chunk->help_version);
        $this->assertSame(['area' => 'billing'], $chunk->tags);
        $this->assertSame(sha1($chunk->source.$chunk->heading_path.$chunk->position), $chunk->chunk_key);
        $this->assertNotNull($chunk->source_modified_at);
        $this->assertCount(768, $chunk->embedding);
    }

    public function test_handle_defaults_the_version_to_the_help_center_fingerprint(): void
    {
        $this->fakeEmbeddings();

        $this->artisan('help:ingest')->assertSuccessful();

        $this->assertSame(app(HelpVersion::class)->current(), HelpChunk::query()->value('help_version'));
    }

    public function test_handle_embeds_nothing_the_second_time(): void
    {
        $this->fakeEmbeddings();
        $this->artisan('help:ingest', ['--docs-version' => 'v1'])->assertSuccessful();
        $count = HelpChunk::query()->count();
        Http::fake();

        $this->artisan('help:ingest', ['--docs-version' => 'v2'])
            ->expectsOutputToContain("Diff vs v1: 0 changed, 0 new, 0 removed, {$count} unchanged")
            ->expectsOutputToContain('Embedding 0 chunks')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(['v2'], HelpChunk::query()->pluck('help_version')->unique()->all());
    }

    public function test_handle_reembeds_only_the_chunk_that_changed(): void
    {
        $this->fakeEmbeddings();
        $this->artisan('help:ingest', ['--docs-version' => 'v1'])->assertSuccessful();
        HelpChunk::query()->where('source', 'help/billing/late-fees.md')->where('position', 1)->update(['content' => 'Stale text']);
        Http::fake(['*' => Http::response($this->embeddingBody())]);

        $this->artisan('help:ingest', ['--docs-version' => 'v2'])
            ->expectsOutputToContain('1 changed, 0 new, 0 removed')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertStringNotContainsString('Stale text', HelpChunk::query()->where('source', 'help/billing/late-fees.md')->where('position', 1)->value('content'));
    }

    public function test_handle_adds_missing_chunks_and_removes_chunks_of_vanished_articles(): void
    {
        $this->fakeEmbeddings();
        $this->artisan('help:ingest', ['--docs-version' => 'v1'])->assertSuccessful();
        HelpChunk::query()->where('source', 'help/billing/late-fees.md')->where('position', 1)->delete();
        $this->seedChunk('gone', 'Section', 'Text.', $this->axisVector(1), version: 'v1');
        Http::fake(['*' => Http::response($this->embeddingBody())]);

        $this->artisan('help:ingest', ['--docs-version' => 'v2'])
            ->expectsOutputToContain('0 changed, 1 new, 1 removed')
            ->assertSuccessful();

        $this->assertFalse(HelpChunk::query()->where('source', 'help/billing/gone.md')->exists());
        Http::assertSentCount(1);
    }

    public function test_handle_with_force_reembeds_every_chunk(): void
    {
        $this->fakeEmbeddings();
        $this->artisan('help:ingest')->assertSuccessful();
        $count = HelpChunk::query()->count();
        Http::fake(['*' => Http::response($this->embeddingBody())]);

        $this->artisan('help:ingest', ['--force' => true])->assertSuccessful();

        Http::assertSentCount($count);
    }

    public function test_handle_fails_and_changes_nothing_when_the_model_server_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->artisan('help:ingest')
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();

        $this->assertSame(0, HelpChunk::query()->count());
    }

    public function test_the_table_has_a_generated_full_text_column_and_a_unique_chunk_key(): void
    {
        $this->seedChunk('late-fees', 'Overview', 'A late fee is 1.5 percent.', $this->axisVector(0));

        $matches = DB::scalar("select count(*) from help_chunks where search @@ websearch_to_tsquery('english', 'late fee')");
        $index = DB::scalar("select indexdef from pg_indexes where indexname = 'help_chunks_search_idx'");

        $this->assertSame(1, $matches);
        $this->assertStringContainsString('USING gin (search)', $index);
        $this->assertSame(1, DB::scalar("select count(*) from pg_indexes where tablename = 'help_chunks' and indexdef like '%UNIQUE%(chunk_key)%'"));
    }

    private function fakeEmbeddings(): void
    {
        Http::fake(['*' => Http::response($this->embeddingBody())]);
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
