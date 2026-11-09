<?php

namespace Tests\Feature\Console;

use App\Models\HelpChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class HelpIngestParentChildTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_stores_unembedded_parents_and_embedded_children_pointing_at_them(): void
    {
        $this->fakeModel();

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--docs-version' => 'v1'])
            ->expectsOutputToContain('Parent-child:')
            ->expectsOutput('Upserted. help_version = v1')
            ->assertSuccessful();

        $parents = HelpChunk::query()->where('kind', 'parent');
        $children = HelpChunk::query()->where('kind', 'child');

        $this->assertGreaterThan(100, $parents->count());
        $this->assertGreaterThanOrEqual($parents->count(), $children->count());
        $this->assertSame(0, HelpChunk::query()->where('kind', 'parent')->whereNotNull('embedding')->count());
        $this->assertSame(0, HelpChunk::query()->where('kind', 'child')->whereNull('embedding')->count());
        $this->assertSame(0, HelpChunk::query()->where('kind', 'child')->whereNull('parent_id')->count());
    }

    public function test_handle_gives_every_child_the_heading_label_as_its_header_without_a_model_call(): void
    {
        $this->fakeModel();

        $this->artisan('help:ingest', ['--strategy' => 'parent-child'])->assertSuccessful();

        $child = HelpChunk::query()->where('kind', 'child')->where('source', 'help/billing/late-fees.md')->orderBy('position')->firstOrFail();

        $this->assertSame($child->heading, $child->context_header);
        $this->assertStringStartsWith('Understanding late fees › ', $child->context_header);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/completions'));
    }

    public function test_handle_embeds_the_header_and_the_child_text_together(): void
    {
        $this->fakeModel();

        $this->artisan('help:ingest', ['--strategy' => 'parent-child'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/embeddings')
            && str_starts_with($request['input'], "search_document: Understanding late fees › How the fee is calculated\n\n"));
    }

    public function test_handle_embeds_nothing_the_second_time(): void
    {
        $this->fakeModel();
        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--docs-version' => 'v1'])->assertSuccessful();
        Http::fake();

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--docs-version' => 'v2'])
            ->expectsOutputToContain('0 changed, 0 new, 0 removed')
            ->expectsOutputToContain('Embedding 0 chunks')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(['v2'], HelpChunk::query()->pluck('help_version')->unique()->all());
    }

    public function test_handle_reembeds_only_the_child_that_changed(): void
    {
        $this->fakeModel();
        $this->artisan('help:ingest', ['--strategy' => 'parent-child'])->assertSuccessful();
        HelpChunk::query()->where('kind', 'child')->where('source', 'help/billing/late-fees.md')->orderBy('position')->firstOrFail()->update(['content' => 'Stale text']);
        $this->fakeModel();

        $this->artisan('help:ingest', ['--strategy' => 'parent-child'])
            ->expectsOutputToContain('1 changed, 0 new, 0 removed')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_handle_with_context_headers_asks_the_model_once_per_child_and_stores_the_sentence(): void
    {
        $this->fakeModel(context: 'It explains how the late fee is calculated.');

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--context-headers' => true])->assertSuccessful();

        $children = HelpChunk::query()->where('kind', 'child');

        Http::assertSentCount($children->count() * 2);
        $this->assertSame(0, HelpChunk::query()->where('kind', 'child')->where('context_header', 'not like', '% — It explains how the late fee is calculated.')->count());
        $this->assertDatabaseHas('model_calls', ['feature' => 'retrieval.context']);
    }

    public function test_handle_with_context_headers_embeds_the_generated_context_and_indexes_it_for_keywords(): void
    {
        $this->fakeModel(context: 'Zanzibar surcharge rules.');

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--context-headers' => true])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/embeddings')
            && str_contains($request['input'], ' — Zanzibar surcharge rules.'));
        $this->assertGreaterThan(0, HelpChunk::query()->whereRaw("search @@ websearch_to_tsquery('english', 'zanzibar')")->count());
    }

    public function test_handle_reuses_stored_contexts_instead_of_calling_the_model_again(): void
    {
        $this->fakeModel(context: 'Stored context.');
        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--context-headers' => true])->assertSuccessful();
        Http::fake();

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--context-headers' => true])
            ->expectsOutputToContain('Embedding 0 chunks')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_handle_generates_contexts_for_children_that_only_had_the_plain_header(): void
    {
        $this->fakeModel();
        $this->artisan('help:ingest', ['--strategy' => 'parent-child'])->assertSuccessful();
        $count = HelpChunk::query()->where('kind', 'child')->count();
        $this->fakeModel(context: 'Now with context.');

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--context-headers' => true])
            ->expectsOutputToContain("{$count} changed, 0 new, 0 removed, 0 unchanged")
            ->assertSuccessful();

        $this->assertSame($count, HelpChunk::query()->where('kind', 'child')->where('context_header', 'like', '% — Now with context.')->count());
    }

    public function test_handle_switches_back_to_heading_chunks_dropping_children_and_reembedding_parents(): void
    {
        $this->fakeModel();
        $this->artisan('help:ingest', ['--strategy' => 'parent-child'])->assertSuccessful();
        $parents = HelpChunk::query()->where('kind', 'parent')->count();
        $this->fakeModel();

        $this->artisan('help:ingest', ['--strategy' => 'heading'])->assertSuccessful();

        $this->assertSame(0, HelpChunk::query()->where('kind', 'child')->count());
        $this->assertSame($parents, HelpChunk::query()->where('kind', 'parent')->whereNotNull('embedding')->count());
    }

    public function test_handle_defaults_the_strategy_to_config(): void
    {
        config(['ai.chunking.strategy' => 'parent-child']);
        $this->fakeModel();

        $this->artisan('help:ingest')->expectsOutputToContain('Parent-child:')->assertSuccessful();
    }

    public function test_handle_rejects_an_unknown_strategy(): void
    {
        $this->artisan('help:ingest', ['--strategy' => 'semantic'])
            ->expectsOutputToContain('Unknown strategy [semantic]')
            ->assertFailed();
    }

    public function test_handle_rejects_context_headers_without_parent_child(): void
    {
        $this->artisan('help:ingest', ['--strategy' => 'heading', '--context-headers' => true])
            ->expectsOutputToContain('--context-headers needs --strategy=parent-child')
            ->assertFailed();
    }

    public function test_handle_stops_when_the_context_model_returns_nothing(): void
    {
        $this->fakeModel(context: '   ');

        $this->artisan('help:ingest', ['--strategy' => 'parent-child', '--context-headers' => true])
            ->expectsOutputToContain('returned no context')
            ->assertFailed();

        $this->assertSame(0, HelpChunk::query()->count());
    }

    public function test_handle_indexes_extra_documents_from_a_source_directory(): void
    {
        $directory = sys_get_temp_dir().'/imports-'.uniqid();
        File::makeDirectory($directory);
        File::put("{$directory}/refund-policy.md", "# Refund policy\n\n## Window\n\nRefunds are possible for thirty days.");
        File::put("{$directory}/vat-note.html", '<html><body><main><h1>VAT note</h1><h2>Rate</h2><p>Twenty percent.</p></main></body></html>');
        $this->fakeModel();

        $this->artisan('help:ingest', ['--strategy' => 'heading', '--source' => $directory])
            ->expectsOutputToContain('Parsed 2 imported documents')
            ->assertSuccessful();

        File::deleteDirectory($directory);

        $sources = HelpChunk::query()->where('source', 'like', 'help/imports/%')->pluck('source')->unique()->sort()->values()->all();
        $this->assertSame(['help/imports/refund-policy.md', 'help/imports/vat-note.md'], $sources);
        $this->assertSame(['area' => 'imports'], HelpChunk::query()->where('source', 'help/imports/vat-note.md')->value('tags'));
    }

    public function test_handle_fails_for_a_source_that_is_not_a_directory(): void
    {
        $this->artisan('help:ingest', ['--source' => '/no/such/directory'])
            ->expectsOutputToContain('it is not a directory')
            ->assertFailed();
    }

    private function fakeModel(string $context = 'Context sentence.'): void
    {
        Http::swap(new Factory);

        Http::fake(function (Request $request) use ($context) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response([
                    'data' => [
                        [
                            'embedding' => array_fill(0, 768, 0.1),
                        ],
                    ],
                ]);
            }

            return Http::response($this->chatReply($context));
        });
    }
}
