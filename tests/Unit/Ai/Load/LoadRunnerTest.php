<?php

namespace Tests\Unit\Ai\Load;

use App\Ai\Exceptions\UnusableLoadTest;
use App\Ai\Load\BenchServeCommand;
use App\Ai\Load\LoadRunner;
use App\Ai\Load\ShapeSummary;
use App\Ai\Load\TrafficShape;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBenchProcess;

class LoadRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/ledgerly-load-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_the_command_line_targets_the_chat_endpoint_with_the_shape_of_the_traffic(): void
    {
        $arguments = (new LoadRunner(new FakeBenchProcess))->commandFor($this->command(), 16, 0, $this->directory);

        $this->assertSame(['vllm', 'bench', 'serve'], array_slice($arguments, 0, 3));
        $this->assertSame('openai-chat', $this->after($arguments, '--backend'));
        $this->assertSame('http://127.0.0.1:8001', $this->after($arguments, '--base-url'));
        $this->assertSame('/v1/chat/completions', $this->after($arguments, '--endpoint'));
        $this->assertSame('neuralmagic/Meta-Llama-3.1-8B-Instruct-FP8', $this->after($arguments, '--model'));
        $this->assertSame('ledgerly-support', $this->after($arguments, '--served-model-name'));
        $this->assertSame('random', $this->after($arguments, '--dataset-name'));
        $this->assertSame('16', $this->after($arguments, '--max-concurrency'));
        $this->assertSame('level-16.json', $this->after($arguments, '--result-filename'));
        $this->assertContains('--ignore-eos', $arguments);
        $this->assertContains('--save-result', $arguments);
        $this->assertSame('50,99', $this->after($arguments, '--metric-percentiles'));
        $this->assertSame('ttft,tpot,itl,e2el', $this->after($arguments, '--percentile-metrics'));
    }

    public function test_the_random_prompt_is_the_shared_prefix_plus_the_rest_of_the_input(): void
    {
        $arguments = (new LoadRunner(new FakeBenchProcess))->commandFor($this->command(), 8, 0, $this->directory);

        $this->assertSame('1650', $this->after($arguments, '--random-input-len'));
        $this->assertSame('450', $this->after($arguments, '--random-prefix-len'));
        $this->assertSame('180', $this->after($arguments, '--random-output-len'));
    }

    public function test_a_prefix_as_long_as_the_whole_input_still_leaves_one_token_of_suffix(): void
    {
        $command = new BenchServeCommand('vllm', 'http://x', 'a', 'b', ShapeSummary::of([new TrafficShape('f', 100, 10, 100)]));

        $arguments = (new LoadRunner(new FakeBenchProcess))->commandFor($command, 1, 0, $this->directory);

        $this->assertSame('1', $this->after($arguments, '--random-input-len'));
    }

    public function test_the_prompt_count_is_eight_per_stream_with_a_floor_and_an_override(): void
    {
        $runner = new LoadRunner(new FakeBenchProcess);

        $this->assertSame(32, $runner->promptsFor(1, 0));
        $this->assertSame(512, $runner->promptsFor(64, 0));
        $this->assertSame(100, $runner->promptsFor(64, 100));
    }

    public function test_it_runs_the_process_and_reads_the_result_file(): void
    {
        $process = new FakeBenchProcess;

        $result = (new LoadRunner($process))->runLevel($this->command(), 16, 0, $this->directory, ['OPENAI_API_KEY' => 'k'], 60);

        $this->assertSame(16, $result->concurrency);
        $this->assertSame(11.8, $result->requestsPerSecond);
        $this->assertSame(1290.0, $result->outputTokensPerSecond);
        $this->assertSame(340.0, $result->ttftP50);
        $this->assertSame(720.0, $result->ttftP99);
        $this->assertSame(15.0, $result->itlP50);
        $this->assertSame(26.0, $result->itlP99);
        $this->assertSame(['OPENAI_API_KEY' => 'k'], $process->environments[0]);
    }

    public function test_a_missing_binary_is_reported_before_anything_runs(): void
    {
        $process = new FakeBenchProcess(available: false);

        try {
            (new LoadRunner($process))->runLevel($this->command(), 1, 0, $this->directory, [], 60);

            $this->fail('Expected UnusableLoadTest');
        } catch (UnusableLoadTest $exception) {
            $this->assertStringContainsString('The [vllm] command was not found', $exception->getMessage());
            $this->assertSame([], $process->commands);
        }
    }

    public function test_a_failed_run_reports_the_exit_code_and_the_tail_of_the_output(): void
    {
        $this->expectException(UnusableLoadTest::class);
        $this->expectExceptionMessage('failed at concurrency 8 (exit 1): line 1 | line 2 | Traceback: connection refused');

        (new LoadRunner(new FakeBenchProcess(exitCode: 1)))->runLevel($this->command(), 8, 0, $this->directory, [], 60);
    }

    public function test_a_clean_exit_without_a_result_file_is_an_error(): void
    {
        $this->expectException(UnusableLoadTest::class);
        $this->expectExceptionMessage('wrote no readable result');

        (new LoadRunner(new FakeBenchProcess(writesResult: false)))->runLevel($this->command(), 8, 0, $this->directory, [], 60);
    }

    private function command(): BenchServeCommand
    {
        return new BenchServeCommand(
            binary: 'vllm',
            baseUrl: 'http://127.0.0.1:8001',
            servedModel: 'ledgerly-support',
            tokenizerSource: 'neuralmagic/Meta-Llama-3.1-8B-Instruct-FP8',
            shape: new ShapeSummary(requests: 100, inputTokens: 2100, outputTokens: 180, prefixTokens: 450),
        );
    }

    private function after(array $arguments, string $flag): string
    {
        return (string) $arguments[array_search($flag, $arguments, true) + 1];
    }
}
