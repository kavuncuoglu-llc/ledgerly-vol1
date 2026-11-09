<?php

namespace Tests\Feature\Console;

use App\Ai\Load\BenchProcess;
use App\Ai\Load\TrafficShape;
use App\Ai\Load\TrafficShapeFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBenchProcess;
use Tests\TestCase;

class AiLoadTritonTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $shapeFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/ledgerly-ai-load-triton-'.uniqid();

        $this->shapeFile = $this->directory.'/traffic-shape.jsonl';

        config()->set('ai.load.directory', $this->directory);
        config()->set('ai.load.shape_file', $this->shapeFile);
        config()->set('ai.triton.tokenizer', 'meta-llama/Llama-3.1-8B-Instruct');
        config()->set('ai.providers.triton.api_key', 'triton-secret');

        TrafficShapeFile::write($this->shapeFile, [new TrafficShape('support.reply', 2100, 180, 450)]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_dry_run_against_triton_passes_the_auth_header_to_the_bench_client_and_the_triton_tokenizer(): void
    {
        $this->artisan('ai:load', ['--provider' => 'triton', '--concurrency' => '8', '--dry-run' => true])
            ->expectsOutputToContain("'--header' 'x-api-key=triton-secret'")
            ->assertSuccessful();
    }

    public function test_the_tokenizer_comes_from_the_triton_setting_and_the_served_model_is_the_directory_name(): void
    {
        $this->artisan('ai:load', ['--provider' => 'triton', '--concurrency' => '8', '--dry-run' => true])
            ->expectsOutputToContain("'--model' 'meta-llama/Llama-3.1-8B-Instruct' '--served-model-name' 'chat'")
            ->assertSuccessful();
    }

    public function test_a_real_run_sends_no_bearer_key_in_the_environment_because_triton_does_not_read_one(): void
    {
        $process = new FakeBenchProcess;

        $this->app->instance(BenchProcess::class, $process);

        $this->artisan('ai:load', ['--provider' => 'triton', '--concurrency' => '8'])->run();

        $this->assertSame(['OPENAI_API_KEY' => ''], $process->environments[0] ?? []);
        $this->assertContains('--header', $process->commands[0] ?? []);
    }

    public function test_a_vllm_dry_run_still_has_no_header_argument(): void
    {
        config()->set('ai.vllm.repository', 'neuralmagic/Meta-Llama-3.1-8B-Instruct-FP8');

        $this->artisan('ai:load', ['--provider' => 'vllm', '--concurrency' => '8', '--dry-run' => true])
            ->doesntExpectOutputToContain('--header')
            ->assertSuccessful();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
