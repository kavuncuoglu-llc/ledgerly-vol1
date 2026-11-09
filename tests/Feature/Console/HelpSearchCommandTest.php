<?php

namespace Tests\Feature\Console;

use App\Models\HelpChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HelpSearchCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        DB::statement('SET LOCAL enable_indexscan = off');
    }

    public function test_handle_prints_the_nearest_chunks_with_similarity_source_and_heading(): void
    {
        $this->chunk('help/billing/late-fees.md', 1, 'Understanding late fees › Overview', 0);
        $this->chunk('help/invoices/statuses.md', 1, 'Invoice statuses › Overdue', 1);
        Http::fake(['*' => Http::response($this->embeddingBody(0))]);

        $this->artisan('help:search', ['question' => 'the March bill is past due'])
            ->expectsOutput('1.00 help/billing/late-fees.md › Understanding late fees › Overview')
            ->expectsOutput('0.00 help/invoices/statuses.md › Invoice statuses › Overdue')
            ->assertSuccessful();
    }

    public function test_handle_embeds_the_question_with_the_query_prefix(): void
    {
        $this->chunk('help/billing/late-fees.md', 1, 'Understanding late fees › Overview', 0);
        Http::fake(['*' => Http::response($this->embeddingBody(0))]);

        $this->artisan('help:search', ['question' => 'why a late fee'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['input'] === 'search_query: why a late fee');
    }

    public function test_handle_limits_the_number_of_printed_chunks(): void
    {
        $this->chunk('help/a.md', 1, 'A › One', 0);
        $this->chunk('help/b.md', 1, 'B › One', 1);
        $this->chunk('help/c.md', 1, 'C › One', 2);
        Http::fake(['*' => Http::response($this->embeddingBody(0))]);

        $this->artisan('help:search', ['question' => 'anything', '--limit' => 1])
            ->expectsOutput('1.00 help/a.md › A › One')
            ->doesntExpectOutput('0.00 help/b.md › B › One')
            ->assertSuccessful();
    }

    public function test_handle_asks_for_help_embed_when_nothing_is_indexed(): void
    {
        Http::fake();

        $this->artisan('help:search', ['question' => 'anything'])
            ->expectsOutput('help_chunks is empty. Run php artisan help:embed first.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_handle_reports_a_model_server_error_and_fails(): void
    {
        $this->chunk('help/a.md', 1, 'A › One', 0);
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->artisan('help:search', ['question' => 'anything'])
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();
    }

    private function chunk(string $source, int $position, string $heading, int $axis): HelpChunk
    {
        return HelpChunk::query()->create([
            'source' => $source,
            'position' => $position,
            'heading' => $heading,
            'content' => $heading,
            'tokens' => 5,
            'embedding' => $this->unitVector($axis),
        ]);
    }

    private function embeddingBody(int $axis): array
    {
        return [
            'data' => [
                [
                    'embedding' => $this->unitVector($axis),
                ],
            ],
        ];
    }

    private function unitVector(int $axis): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }
}
