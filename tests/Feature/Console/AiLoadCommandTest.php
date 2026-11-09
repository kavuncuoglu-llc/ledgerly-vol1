<?php

namespace Tests\Feature\Console;

use App\Ai\Load\BenchProcess;
use App\Ai\Load\BenchProcessResult;
use App\Ai\Load\TrafficShape;
use App\Ai\Load\TrafficShapeFile;
use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBenchProcess;
use Tests\TestCase;

class AiLoadCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $shapeFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/ledgerly-ai-load-'.uniqid();

        $this->shapeFile = $this->directory.'/traffic-shape.jsonl';

        config()->set('ai.load.directory', $this->directory);
        config()->set('ai.load.shape_file', $this->shapeFile);
        config()->set('ai.vllm.repository', 'neuralmagic/Meta-Llama-3.1-8B-Instruct-FP8');
        config()->set('ai.providers.vllm.api_key', 'secret-key');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_runs_each_level_prints_the_table_and_estimates_the_knee(): void
    {
        $process = $this->fakeProcess();

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--concurrency' => '1,8,16,32'])
            ->expectsOutputToContain('traffic shape: 1 requests · input p50 2100 tokens · output p50 180 tokens · shared prefix p50 450 tokens')
            ->expectsOutputToContain('concurrency 16: running vllm bench serve')
            ->expectsOutputToContain('knee ≈ 19 concurrent for TTFT p99 < 1000 ms: set AI_CONCURRENCY=19 for the ai queue')
            ->assertSuccessful();

        $this->assertCount(4, $process->commands);
        $this->assertSame(['OPENAI_API_KEY' => 'secret-key'], $process->environments[0]);
    }

    public function test_the_table_has_a_row_per_level_with_the_percentiles(): void
    {
        $this->fakeProcess();

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--concurrency' => '16,32'])
            ->expectsTable(
                ['concurrency', 'req/s', 'out tok/s', 'TTFT p50', 'TTFT p99', 'ITL p50', 'ITL p99', 'failed'],
                [
                    [16, '11.8', '1,290', '340 ms', '720 ms', '15 ms', '26 ms', 0],
                    [32, '17.9', '1,960', '610 ms', '1,900 ms', '22 ms', '41 ms', 0],
                ],
            )
            ->assertSuccessful();
    }

    public function test_it_saves_the_levels_and_the_knee_next_to_the_other_benchmarks(): void
    {
        $this->fakeProcess();

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--concurrency' => '1,16,32'])->assertSuccessful();

        $saved = glob($this->directory.'/load-*.json');

        $this->assertCount(1, $saved);

        $report = json_decode((string) file_get_contents($saved[0]), true);

        $this->assertSame('vllm', $report['provider']);
        $this->assertCount(3, $report['levels']);
        $this->assertSame(19, $report['knee']['concurrency']);
    }

    public function test_export_shape_builds_the_file_from_model_calls_first(): void
    {
        $this->fakeProcess();

        ModelCall::create([
            'feature' => 'support.reply',
            'provider' => 'vllm',
            'model' => 'ledgerly-support',
            'prompt_tokens' => 1900,
            'completion_tokens' => 120,
            'finish_reason' => 'stop',
            'latency_ms' => 2000,
            'context_breakdown' => ['system' => 450],
        ]);

        $this->artisan('ai:load', ['--export-shape' => true, '--concurrency' => '1'])
            ->expectsOutputToContain('wrote 1 request shapes from model_calls (support.reply, last 7d)')
            ->expectsOutputToContain('input p50 1900 tokens · output p50 120 tokens · shared prefix p50 450 tokens')
            ->assertSuccessful();

        $this->assertFileExists($this->shapeFile);
    }

    public function test_export_shape_with_no_traffic_says_so_and_runs_nothing(): void
    {
        $process = $this->fakeProcess();

        $this->artisan('ai:load', ['--export-shape' => true])
            ->expectsOutputToContain('No answered model_calls for [support.reply] in the last 7d')
            ->assertFailed();

        $this->assertSame([], $process->commands);
    }

    public function test_a_missing_shape_file_tells_you_to_export_it(): void
    {
        $this->fakeProcess();

        $this->artisan('ai:load')
            ->expectsOutputToContain('ai:load --export-shape')
            ->assertFailed();
    }

    public function test_dry_run_prints_the_commands_and_runs_nothing(): void
    {
        $process = $this->fakeProcess();

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--concurrency' => '4,8', '--dry-run' => true])
            ->expectsOutputToContain("'--max-concurrency' '4'")
            ->expectsOutputToContain("'--max-concurrency' '8'")
            ->expectsOutputToContain('dry run: nothing was sent')
            ->assertSuccessful();

        $this->assertSame([], $process->commands);
    }

    public function test_a_provider_that_is_not_vllm_is_refused(): void
    {
        $this->fakeProcess();

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--provider' => 'local'])
            ->expectsOutputToContain('Provider [local] is not a vLLM server or a Triton OpenAI frontend')
            ->assertFailed();
    }

    public function test_an_unknown_provider_is_reported(): void
    {
        $this->artisan('ai:load', ['--provider' => 'nope'])->assertFailed();
    }

    public function test_a_bad_concurrency_list_is_refused(): void
    {
        $this->fakeProcess();

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--concurrency' => '1,eight'])
            ->expectsOutputToContain('--concurrency must be a comma-separated list of positive whole numbers')
            ->assertFailed();
    }

    public function test_without_the_tokenizer_source_it_asks_for_vllm_model(): void
    {
        $this->fakeProcess();

        config()->set('ai.vllm.repository', null);

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load')
            ->expectsOutputToContain('Set VLLM_MODEL')
            ->assertFailed();
    }

    public function test_a_failing_level_prints_the_levels_that_finished_and_the_error(): void
    {
        $this->app->instance(BenchProcess::class, new class extends FakeBenchProcess
        {
            public function run(array $command, array $environment, int $timeoutSeconds): BenchProcessResult
            {
                if (in_array('8', $command, true)) {
                    return new BenchProcessResult(1, 'Traceback: connection reset');
                }

                return parent::run($command, $environment, $timeoutSeconds);
            }
        });

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load', ['--concurrency' => '1,8'])
            ->expectsOutputToContain('failed at concurrency 8 (exit 1): Traceback: connection reset')
            ->assertFailed();
    }

    public function test_a_missing_vllm_command_is_explained(): void
    {
        $this->app->instance(BenchProcess::class, new FakeBenchProcess(available: false));

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);

        $this->artisan('ai:load')
            ->expectsOutputToContain('The [vllm] command was not found')
            ->assertFailed();
    }

    private function fakeProcess(): FakeBenchProcess
    {
        $process = new FakeBenchProcess;

        $this->app->instance(BenchProcess::class, $process);

        return $process;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;

            is_dir($path)
                ? $this->removeDirectory($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}
