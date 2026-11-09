<?php

namespace Tests\Feature\Console;

use App\Ai\Events\ModelCalled;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiLayerCommandsTest extends TestCase
{
    public function test_ai_providers_lists_every_provider_with_its_capabilities(): void
    {
        $status = Artisan::call('ai:providers');

        $output = Artisan::output();
        $this->assertSame(0, $status);
        $this->assertStringContainsString('tools, structured, seed, ctx-override', $output);
        $this->assertStringContainsString('embed (768)', $output);
        $this->assertStringContainsString('(not configured)', $output);
        $this->assertStringContainsString('tools, structured, vision, cache-breakpoints, native-structured', $output);
        $this->assertStringContainsString('everything', $output);
        $this->assertStringContainsString('default provider: local', $output);
    }

    public function test_ai_providers_check_asks_the_provider_which_models_it_serves(): void
    {
        Http::fake([
            'localhost:11434/v1/models' => Http::response(['data' => [['id' => 'llama3.1:8b'], ['id' => 'nomic-embed-text']]]),
        ]);

        Artisan::call('ai:providers', ['--check' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('serves llama3.1:8b', $output);
        $this->assertStringContainsString('reachable, does not list llama3.2-vision:11b', $output);
    }

    public function test_ai_providers_check_reports_an_unreachable_server(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);

        Artisan::call('ai:providers', ['--check' => true]);

        $this->assertStringContainsString('unreachable', Artisan::output());
    }

    public function test_ai_boundaries_passes_on_the_application(): void
    {
        $this->artisan('ai:boundaries')
            ->expectsOutputToContain('boundary holds')
            ->assertSuccessful();
    }

    public function test_ai_record_saves_the_exchange_with_the_key_redacted(): void
    {
        Event::fake([ModelCalled::class]);
        config()->set('ai.providers.local.api_key', 'sk-secret-value');
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'llama3.1:8b',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'billing'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
        ])]);
        $directory = 'storage/framework/testing/fixtures-'.uniqid();

        try {
            $this->artisan('ai:record', [
                '--provider' => 'local',
                '--fixture' => 'short-answer',
                '--prompt' => 'tests/Fixtures/ai/prompts/short-answer.json',
                '--path' => $directory,
            ])->expectsOutputToContain('short-answer.json')->assertSuccessful();

            $path = base_path("{$directory}/openai-compatible/short-answer.json");
            $fixture = json_decode((string) file_get_contents($path), true);

            $this->assertSame('openai-compatible', $fixture['driver']);
            $this->assertSame('[redacted]', $fixture['request']['headers']['Authorization']);
            $this->assertStringNotContainsString('sk-secret-value', (string) file_get_contents($path));
            $this->assertCount(2, $fixture['request']['body']['messages']);
            $this->assertSame(200, $fixture['response']['status']);
            $this->assertStringContainsString('"content":"billing"', $fixture['response']['body']);
        } finally {
            File::deleteDirectory(base_path($directory));
        }
    }

    public function test_ai_record_needs_a_fixture_name(): void
    {
        $this->artisan('ai:record', ['--prompt' => 'tests/Fixtures/ai/prompts/short-answer.json'])
            ->expectsOutputToContain('--fixture')
            ->assertFailed();
    }
}
