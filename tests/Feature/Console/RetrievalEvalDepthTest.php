<?php

namespace Tests\Feature\Console;

use App\Ai\Exceptions\UnsupportedReranker;
use App\Ai\Retrieval\LexicalReranker;
use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\QueryRewritingRetriever;
use App\Ai\Retrieval\RerankingRetriever;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class RetrievalEvalDepthTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/retrieval-depth-'.uniqid();

        File::makeDirectory($this->directory);

        app()->useStoragePath($this->directory);

        $this->disableVectorIndexScans();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_handle_with_a_reranker_moves_the_right_chunk_up(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeEmbeddingsAndChat(['minimum late fee amount' => $this->axisVector(0)]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'none'])->assertSuccessful();
        $withoutReranker = $this->storedRecall(1);
        File::deleteDirectory($this->directory.'/golden');

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'lexical'])
            ->expectsOutputToContain('rerank lexical')
            ->assertSuccessful();

        $this->assertSame(0.0, $withoutReranker);
        $this->assertSame(1.0, $this->storedRecall(1));
    }

    public function test_handle_prints_the_rewrite_mode_and_query_latency(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeEmbeddingsAndChat(['minimum late fee amount' => $this->axisVector(0)]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--rewrite' => 'off', '--rerank' => 'none'])
            ->expectsOutputToContain('rerank none · rewrite off · chunks by heading')
            ->expectsOutputToContain('query latency:')
            ->assertSuccessful();
    }

    public function test_handle_with_rewriting_retrieves_on_the_rewrite_and_the_original(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeEmbeddingsAndChat(
            [
                'my client keeps paying late' => $this->axisVector(30),
                'automatic reminders' => $this->axisVector(1),
            ],
            [$this->chatReply('automatic reminders')],
        );
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'my client keeps paying late', 'chunks' => ['reminders›Schedule']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'none', '--rewrite' => 'rewrite'])
            ->expectsOutputToContain('rewrite rewrite')
            ->assertSuccessful();

        $this->assertSame(1.0, $this->storedRecall(5));
    }

    public function test_handle_rejects_an_unknown_reranker_and_rewrite_mode(): void
    {
        $this->seedLateFeeCorpus();
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'x', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--rerank' => 'cohere'])
            ->expectsOutputToContain('Unknown reranker driver [cohere]')
            ->assertFailed();

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--rewrite' => 'bogus'])
            ->assertFailed();
    }

    public function test_handle_reports_an_unreachable_sidecar_without_a_stack_trace(): void
    {
        $this->seedLateFeeCorpus();
        Http::fake(['*/embeddings' => Http::response(['data' => [['embedding' => $this->axisVector(0)]]]), '*/rerank' => Http::response('down', 503)]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'sidecar'])
            ->expectsOutputToContain('The sidecar answered HTTP 503')
            ->assertFailed();
    }

    public function test_handle_steps_prints_stage_one_then_rerank_then_rewrite(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeEmbeddingsAndChat(
            ['minimum late fee amount' => $this->axisVector(0)],
            [$this->chatReply('late fee minimum'), $this->chatReply('pizza')],
        );
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
            ['id' => 2, 'question' => 'pizza?', 'chunks' => []],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'lexical', '--steps' => true])
            ->expectsOutputToContain('precision@5')
            ->expectsOutputToContain('stage one (hybrid, heading chunks)')
            ->expectsOutputToContain('+ rerank (lexical, top 20)')
            ->expectsOutputToContain('+ query rewrite')
            ->assertSuccessful();

        $this->assertCount(3, File::glob($this->directory.'/golden/results-*.json'));
    }

    public function test_handle_steps_skips_the_rerank_row_when_there_is_no_reranker(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeEmbeddingsAndChat(['minimum late fee amount' => $this->axisVector(0)], [$this->chatReply('late fee minimum')]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--rerank' => 'none', '--rewrite' => 'off', '--steps' => true])
            ->expectsOutputToContain('+ rerank: skipped')
            ->assertSuccessful();
    }

    public function test_handle_steps_marks_a_step_skipped_when_its_service_is_down(): void
    {
        $this->seedLateFeeCorpus();
        Http::fake(['*/embeddings' => Http::response(['data' => [['embedding' => $this->axisVector(0)]]]), '*/rerank' => Http::response('down', 503), '*' => Http::response($this->chatReply('x'))]);
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'sidecar', '--rewrite' => 'off', '--steps' => true])
            ->expectsOutputToContain('+ rerank (sidecar, top 20): skipped (The sidecar answered HTTP 503')
            ->assertSuccessful();
    }

    public function test_handle_reindex_to_evaluates_a_candidate_model_in_a_bench_table_and_drops_it(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeCandidateModel();
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'lexical', '--embed-model' => 'nomic-embed-text', '--embed-dimensions' => '768', '--reindex-to' => 'bench_nomic'])
            ->expectsOutputToContain('Re-embedding into bench_nomic with nomic-embed-text (768 dims)')
            ->expectsOutputToContain('recall@5 (stage 1)')
            ->expectsOutputToContain('nomic-embed-text')
            ->assertSuccessful();

        $this->assertFalse(Schema::hasTable('bench_nomic'));
        $this->assertSame(7, DB::table('help_chunks')->count());
    }

    public function test_handle_reindex_to_keeps_the_table_when_asked_and_embeds_header_and_text(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeCandidateModel();
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--mode' => 'vector', '--floor' => '0.5', '--rerank' => 'none', '--embed-dimensions' => '768', '--reindex-to' => 'bench_keep', '--keep-index' => true])
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('bench_keep'));
        $this->assertSame(7, DB::table('bench_keep')->whereNotNull('embedding')->count());
        $this->assertSame(1, DB::scalar("select count(*) from bench_keep where search @@ websearch_to_tsquery('english', 'minimum')"));
        DB::statement('DROP TABLE bench_keep');
    }

    public function test_handle_reindex_to_probes_the_candidate_dimensions_when_not_given(): void
    {
        $this->seedLateFeeCorpus();
        $this->fakeCandidateModel();
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'minimum late fee amount', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--rerank' => 'none', '--reindex-to' => 'bench_probe'])
            ->expectsOutputToContain('(768 dims)')
            ->assertSuccessful();
    }

    public function test_handle_reindex_to_refuses_a_table_name_that_is_not_a_bench_table(): void
    {
        $this->seedLateFeeCorpus();
        $golden = $this->goldenFile([
            ['id' => 1, 'question' => 'x', 'chunks' => ['late-fees›Minimum']],
        ]);

        $this->artisan('retrieval:eval', ['--golden' => $golden, '--reindex-to' => 'users'])
            ->expectsOutputToContain('--reindex-to must be a table named bench_<something>')
            ->assertFailed();

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_the_retriever_binding_follows_the_reranker_and_rewrite_config(): void
    {
        config(['ai.retrieval.reranker' => 'lexical', 'ai.retrieval.rewrite' => 'rewrite', 'ai.retrieval.rerank_candidates' => 40]);

        $retriever = app(Retriever::class);

        $this->assertInstanceOf(RerankingRetriever::class, $retriever);
        $this->assertSame(40, $retriever->settings()->candidatePool);
    }

    public function test_the_retriever_binding_is_plain_pgvector_when_both_are_off(): void
    {
        config(['ai.retrieval.reranker' => 'none', 'ai.retrieval.rewrite' => 'off']);

        $this->assertInstanceOf(PgRetriever::class, app(Retriever::class));
    }

    public function test_the_retriever_binding_wraps_only_rewriting_when_the_reranker_is_none(): void
    {
        config(['ai.retrieval.reranker' => 'none', 'ai.retrieval.rewrite' => 'expand']);

        $this->assertInstanceOf(QueryRewritingRetriever::class, app(Retriever::class));
    }

    public function test_the_retriever_binding_refuses_an_unknown_reranker_driver(): void
    {
        config(['ai.retrieval.reranker' => 'cohere']);

        $this->expectException(UnsupportedReranker::class);

        app(Retriever::class);
    }

    public function test_the_lexical_reranker_is_the_offline_stand_in(): void
    {
        $this->assertInstanceOf(LexicalReranker::class, app(RetrieverFactory::class)->rerankerFor('lexical'));
    }

    private function seedLateFeeCorpus(): void
    {
        $this->seedChunk('late-fees', 'Overview', 'Late fees apply to overdue invoices.', $this->blendedVector(0, 1, 0.97));
        $this->seedChunk('late-fees', 'Minimum', 'The minimum late fee amount is one dollar; smaller fees are not charged.', $this->blendedVector(0, 2, 0.90), position: 2);
        $this->seedChunk('reminders', 'Schedule', 'Automatic reminders go out before and after the due date.', $this->blendedVector(1, 3, 0.95), position: 3);

        foreach (range(1, 4) as $number) {
            $this->seedChunk("filler-{$number}", 'Section', "Unrelated filler text {$number}.", $this->blendedVector(0, 10 + $number, 0.60), position: 3 + $number);
        }
    }

    private function storedRecall(int $k): float
    {
        $files = File::glob($this->directory.'/golden/results-*.json');

        sort($files);

        return (float) json_decode(File::get(end($files)), true)['recall'][$k];
    }

    private function fakeCandidateModel(): void
    {
        Http::swap(new Factory);

        Http::fake(function (Request $request) {
            $input = (string) ($request['input'] ?? '');

            $axis = str_contains($input, 'late-fees')
                ? 0
                : 1;

            return Http::response([
                'data' => [
                    [
                        'embedding' => str_starts_with($input, 'search_query')
                            ? $this->axisVector(0)
                            : $this->axisVector($axis),
                    ],
                ],
            ]);
        });
    }

    private function goldenFile(array $rows): string
    {
        $path = $this->directory.'/golden.jsonl';

        File::put($path, implode("\n", array_map('json_encode', $rows))."\n");

        return $path;
    }
}
