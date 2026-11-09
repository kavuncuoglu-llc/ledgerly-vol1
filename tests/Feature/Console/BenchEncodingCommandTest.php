<?php

namespace Tests\Feature\Console;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class BenchEncodingCommandTest extends TestCase
{
    use FakesChatCompletions;
    use RefreshDatabase;

    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = sys_get_temp_dir().'/bench-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDirectory);

        parent::tearDown();
    }

    public function test_tokens_only_prints_every_encoding_without_calling_a_model(): void
    {
        Http::fake();

        $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--rows' => 50, '--output' => $this->outputDirectory])
            ->expectsOutputToContain('json-pretty')
            ->expectsOutputToContain('json-compact')
            ->expectsOutputToContain('yaml')
            ->expectsOutputToContain('markdown-table')
            ->expectsOutputToContain('tsv')
            ->expectsOutputToContain('toon')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_tabular_encodings_cost_fewer_tokens_than_compact_json(): void
    {
        $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--rows' => 50, '--output' => $this->outputDirectory])->assertSuccessful();

        $tokens = $this->savedResults()['results'];

        $byName = array_column($tokens, 'tokens', 'encoding');

        $this->assertGreaterThan($byName['json-compact'], $byName['json-pretty']);
        $this->assertLessThan($byName['json-compact'], $byName['markdown-table']);
        $this->assertLessThan($byName['json-compact'], $byName['tsv']);
        $this->assertLessThan($byName['json-compact'], $byName['toon']);
    }

    public function test_it_writes_the_results_by_date_and_model_slug(): void
    {
        $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--model' => 'llama3.1:8b', '--output' => $this->outputDirectory])
            ->expectsOutputToContain(now()->toDateString().'-llama3.1-8b.json')
            ->assertSuccessful();

        $saved = $this->savedResults();

        $this->assertSame('llama3.1:8b', $saved['model']);
        $this->assertSame(50, $saved['rows']);
        $this->assertCount(6, $saved['results']);
    }

    public function test_it_scores_each_encoding_against_the_expected_answers(): void
    {
        $expected = $this->expectedByQuestion();
        Http::fake(function (Request $request) use ($expected) {
            $content = $request->data()['messages'][1]['content'];

            if (! str_contains($content, 'invoices[')) {
                return Http::response($this->completion('unknown'));
            }

            return Http::response($this->completion($expected[$this->questionIn($content)]));
        });

        $this->artisan('ai:bench-encoding', [
            '--rows' => 120,
            '--questions' => storage_path('benchmarks/invoice-questions.json'),
            '--output' => $this->outputDirectory,
        ])->expectsOutputToContain('20 / 20')->assertSuccessful();

        $results = array_column($this->savedResults()['results'], null, 'encoding');

        $this->assertSame(20, $results['toon']['correct']);
        $this->assertSame(0, $results['tsv']['correct']);
        $this->assertSame(20, $results['tsv']['asked']);
        $this->assertCount(20, $results['tsv']['missed_questions']);
    }

    public function test_it_asks_the_model_named_by_the_model_option_with_the_deterministic_profile(): void
    {
        Http::fake(['*' => Http::response($this->completion('INV-4532'))]);

        $this->artisan('ai:bench-encoding', ['--model' => 'llama3.2:3b', '--encodings' => 'tsv', '--output' => $this->outputDirectory])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'llama3.2:3b'
            && $request->data()['temperature'] === 0
            && $request->data()['max_tokens'] === 20);
        Http::assertSentCount(20);
    }

    public function test_it_logs_every_question_under_the_bench_feature(): void
    {
        Http::fake(['*' => Http::response($this->completion('INV-4532', promptTokens: 700))]);

        $this->artisan('ai:bench-encoding', ['--encodings' => 'toon', '--output' => $this->outputDirectory])->assertSuccessful();

        $this->assertSame(20, ModelCall::query()->where('feature', 'bench.encoding')->count());
    }

    public function test_the_pinned_fixture_date_never_leaks_into_the_usage_log(): void
    {
        Http::fake(['*' => Http::response($this->completion('INV-4532'))]);

        $this->artisan('ai:bench-encoding', ['--encodings' => 'tsv', '--output' => $this->outputDirectory])->assertSuccessful();

        $this->assertTrue(ModelCall::query()->where('feature', 'bench.encoding')->get()->every(fn (ModelCall $call): bool => $call->created_at->isToday()));
    }

    public function test_it_fails_on_an_unknown_encoding_name(): void
    {
        $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--encodings' => 'xml', '--output' => $this->outputDirectory])
            ->expectsOutputToContain('Unknown encoding [xml]')
            ->assertFailed();
    }

    public function test_it_fails_when_the_fixture_is_missing(): void
    {
        $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--fixture' => '/nonexistent.json', '--output' => $this->outputDirectory])
            ->expectsOutputToContain('No benchmark fixture')
            ->assertFailed();
    }

    public function test_it_fails_when_too_few_rows_are_requested(): void
    {
        $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--rows' => 3, '--output' => $this->outputDirectory])
            ->expectsOutputToContain('at least 10 rows')
            ->assertFailed();
    }

    public function test_it_fails_with_the_exception_message_when_the_model_server_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 400)]);

        $this->artisan('ai:bench-encoding', ['--encodings' => 'tsv', '--output' => $this->outputDirectory])
            ->expectsOutputToContain('HTTP 400')
            ->assertFailed();
    }

    private function savedResults(): array
    {
        $files = File::files($this->outputDirectory);

        $this->assertCount(1, $files);

        return json_decode($files[0]->getContents(), true);
    }

    private function expectedByQuestion(): array
    {
        $questions = json_decode((string) file_get_contents(storage_path('benchmarks/invoice-questions.json')), true);

        return array_column($questions, 'expected', 'question');
    }

    private function questionIn(string $content): string
    {
        return trim(substr($content, (int) strrpos($content, 'Question: ') + strlen('Question: ')));
    }
}
