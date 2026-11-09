<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class RetrievalEvalCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/retrieval-eval-'.uniqid();

        File::makeDirectory($this->directory);

        app()->useStoragePath($this->directory);

        $this->disableVectorIndexScans();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_handle_prints_recall_precision_mrr_and_the_empty_expected_count(): void
    {
        $this->seedChunk('late-fees', 'How the fee is calculated', 'The fee is 1.5%.', $this->axisVector(0));
        $this->seedChunk('reminders', 'Schedule', 'Reminders go out.', $this->axisVector(1), position: 2);
        $this->fakeEmbeddingsAndChat([
            'late fee?' => $this->axisVector(0),
            'pizza?' => $this->axisVector(9),
            'quarterly digest?' => $this->axisVector(5),
        ]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'late fee?', 'chunks' => ['late-fees›How the fee is calculated']],
            ['id' => 2, 'question' => 'quarterly digest?', 'chunks' => ['reminders›Schedule']],
            ['id' => 3, 'question' => 'pizza?', 'chunks' => []],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden])
            ->expectsOutputToContain('config: hybrid · threshold 0.70 · k 5 · rrf 60 · nomic-embed-text')
            ->expectsOutputToContain('0.50')
            ->expectsOutputToContain('empty-expected correct: 1/1')
            ->expectsOutputToContain('misses (1): #2')
            ->assertSuccessful();

        $this->assertCount(1, File::glob($this->directory.'/golden/results-*.json'));
        $this->assertCount(1, File::glob($this->directory.'/golden/misses-*.json'));
    }

    public function test_handle_compares_the_recall_with_the_previous_stored_run(): void
    {
        $this->seedChunk('late-fees', 'Overview', 'The fee is 1.5%.', $this->axisVector(0));
        $this->fakeEmbeddingsAndChat(['late fee?' => $this->axisVector(0)]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'late fee?', 'chunks' => ['late-fees›Overview']],
        ]);
        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector'])->assertSuccessful();
        $this->travel(1)->second();

        $this->artisan('retrieval:eval', ['--golden' => $golden])
            ->expectsOutputToContain('(vector): recall@5 1.00')
            ->assertSuccessful();
    }

    public function test_handle_lowering_the_floor_lets_weaker_matches_through(): void
    {
        $this->seedChunk('late-fees', 'Overview', 'Text.', $this->blendedVector(0, 1, 0.6));
        $this->fakeEmbeddingsAndChat(['late fee?' => $this->axisVector(0)]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'late fee?', 'chunks' => ['late-fees›Overview']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector'])
            ->expectsOutputToContain('misses (1)')
            ->assertSuccessful();
        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.55'])
            ->expectsOutputToContain('misses (0)')
            ->assertSuccessful();
    }

    public function test_handle_fails_when_the_golden_set_is_missing(): void
    {
        $this->artisan('retrieval:eval', ['--golden' => $this->directory.'/nope.jsonl'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_handle_fails_on_an_unknown_mode(): void
    {
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'q', 'chunks' => []],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'telepathy'])->assertFailed();
    }

    public function test_handle_fails_when_nothing_has_been_ingested(): void
    {
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'q', 'chunks' => []],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden])
            ->expectsOutputToContain('Run php artisan help:ingest first')
            ->assertFailed();
    }

    private function goldenFile(array $rows): string
    {
        $path = $this->directory.'/golden.jsonl';

        File::put($path, implode("\n", array_map('json_encode', $rows))."\n");

        return $path;
    }
}
