<?php

namespace Tests\Feature\Console;

use App\Ai\Health\WarmRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AiWarmCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config()->set('ai.providers.vllm.base_url', 'http://vllm.test:8000/v1');
        config()->set('ai.providers.vllm.api_key', 'secret');
        config()->set('ai.features', [
            ...config('ai.features'),
            'support.reply' => [
                'provider' => 'vllm',
                'profile' => 'assistant',
            ],
        ]);
    }

    public function test_it_sends_the_support_prompt_twice_and_reports_the_warm_call_and_the_cached_prefix(): void
    {
        $this->serverAnswersWithCachedTokens(420, 440);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])
            ->expectsOutputToContain('ledgerly-support: warm (')
            ->assertSuccessful();

        Http::assertSentCount(3);
    }

    public function test_the_second_calls_cached_tokens_are_what_it_prints(): void
    {
        $this->serverAnswersWithCachedTokens(420, 440);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])
            ->expectsOutputToContain('prefix cached: 420 of 440 prompt tokens')
            ->assertSuccessful();
    }

    public function test_the_warm_request_carries_the_system_prompt_and_a_short_question_and_the_servers_alias(): void
    {
        $this->serverAnswersWithCachedTokens(0, 440);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/completions')
            && $request['model'] === 'ledgerly-support'
            && $request['max_tokens'] === 8
            && str_starts_with($request['messages'][0]['content'], 'You are the support assistant for Ledgerly')
            && $request['messages'][1]['role'] === 'user');
    }

    public function test_it_remembers_when_it_ran_so_the_health_check_can_say_so(): void
    {
        $this->serverAnswersWithCachedTokens(420, 440);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])->assertSuccessful();

        $record = app(WarmRecord::class)->last('vllm');

        $this->assertSame(['ledgerly-support'], $record['models']);
        $this->assertGreaterThan(0, $record['at']);
    }

    public function test_a_server_that_does_not_report_cached_tokens_is_said_to_not_report_them(): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'length']],
                'usage' => ['prompt_tokens' => 440, 'completion_tokens' => 8],
            ]),
        ]);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])
            ->expectsOutputToContain('prefix cache not reported by the server (440 prompt tokens)')
            ->assertSuccessful();
    }

    public function test_a_feature_routed_to_an_adapter_warms_the_adapter_too(): void
    {
        config()->set('ai.features', [
            ...config('ai.features'),
            'ticket.classify' => [
                'provider' => 'vllm',
                'model' => 'classify-lora',
                'profile' => 'deterministic',
            ],
        ]);

        $this->serverAnswersWithCachedTokens(0, 440);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])
            ->expectsOutputToContain('ledgerly-support: warm (')
            ->expectsOutputToContain('classify-lora: warm (')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => ($request['model'] ?? null) === 'classify-lora');
    }

    public function test_it_fails_when_the_server_is_not_reachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('ai:warm', ['--provider' => 'vllm'])
            ->expectsOutputToContain('Provider vllm is not ready: Connection refused')
            ->assertFailed();
    }

    public function test_wait_polls_until_the_server_starts_answering(): void
    {
        Http::fake([
            '*/models' => Http::sequence()
                ->push('', 503)
                ->push(['data' => []]),
            '*/chat/completions' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'length']],
                'usage' => ['prompt_tokens' => 440, 'completion_tokens' => 8],
            ]),
        ]);

        $this->artisan('ai:warm', ['--provider' => 'vllm', '--wait' => 30])->assertSuccessful();
    }

    public function test_it_fails_when_a_model_cannot_be_warmed(): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => Http::response(['error' => ['message' => 'adapter not loaded']], 404),
        ]);

        $this->artisan('ai:warm', ['--provider' => 'vllm'])
            ->expectsOutputToContain('ledgerly-support: could not be warmed')
            ->assertFailed();
    }

    public function test_an_unknown_provider_is_reported(): void
    {
        $this->artisan('ai:warm', ['--provider' => 'nope'])
            ->expectsOutputToContain('No provider named [nope]')
            ->assertFailed();
    }

    private function serverAnswersWithCachedTokens(int $cached, int $prompt): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'ledgerly-support']]]),
            '*/chat/completions' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'length']],
                'usage' => [
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => 8,
                    'prompt_tokens_details' => ['cached_tokens' => $cached],
                ],
            ]),
        ]);
    }
}
