<?php

namespace Tests\Feature\Console;

use App\Ai\Health\WarmRecord;
use App\Ai\Resilience\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDoctorHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-09 14:02:11');

        config()->set('ai.providers.vllm.base_url', 'http://vllm.test:8000/v1');
        config()->set('ai.providers.vllm.api_key', 'secret');
        config()->set('ai.health.warm_ms', 2000);
        config()->set('ai.breaker', ['enabled' => true, 'threshold' => 3, 'open_seconds' => 60, 'memory_seconds' => 600, 'store' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_reachable_loaded_and_quick_server_is_warm(): void
    {
        $this->serverAnswers();

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])
            ->expectsOutputToContain('reachable: yes · loaded: yes (a one-token completion succeeded (12 prompt tokens)) · warm: yes (first completion 0.0 s)')
            ->expectsOutputToContain('breaker: closed')
            ->assertSuccessful();
    }

    public function test_the_warm_state_names_when_ai_warm_last_ran(): void
    {
        $this->serverAnswers();

        app(WarmRecord::class)->remember('vllm', ['ledgerly-support'], 0.4);

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])
            ->expectsOutputToContain('warm: yes (first completion 0.0 s; ai:warm ran at 14:02:11)')
            ->assertSuccessful();
    }

    public function test_a_slow_first_completion_is_reported_cold_and_points_at_ai_warm(): void
    {
        config()->set('ai.health.warm_ms', -1);

        $this->serverAnswers();

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])
            ->expectsOutputToContain('s threshold) → run ai:warm')
            ->assertSuccessful();
    }

    public function test_a_server_that_is_not_listening_is_not_reachable_and_not_loaded(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])
            ->expectsOutputToContain('reachable: no (Connection refused) · loaded: no (not tried) · warm: unknown')
            ->assertFailed();
    }

    public function test_a_server_that_answers_models_but_cannot_complete_is_reachable_but_not_loaded(): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => Http::response(['error' => ['message' => 'model is still loading']], 503),
        ]);

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])
            ->expectsOutputToContain('reachable: yes · loaded: no (HTTP 503: model is still loading) · warm: unknown')
            ->assertFailed();
    }

    public function test_an_open_breaker_fails_the_health_check_even_when_the_server_answers(): void
    {
        $this->serverAnswers();

        $breaker = new CircuitBreaker;

        foreach (range(1, 3) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])
            ->expectsOutputToContain('breaker: OPEN (3 consecutive ProviderUnavailable at 14:02:11; retry at 14:03:11)')
            ->assertFailed();
    }

    public function test_a_triton_provider_is_probed_with_its_own_header_instead_of_a_bearer_token(): void
    {
        config()->set('ai.providers.triton.base_url', 'http://triton.test:9000/v1');
        config()->set('ai.providers.triton.api_key', 'triton-secret');

        $this->serverAnswers();

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'triton'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://triton.test:9000/v1/chat/completions')
            && $request->header('x-api-key') === ['triton-secret']
            && ! $request->hasHeader('Authorization'));
    }

    public function test_a_vllm_provider_is_probed_with_a_bearer_token_and_a_one_token_completion(): void
    {
        $this->serverAnswers();

        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'vllm'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/completions')
            && $request->header('Authorization') === ['Bearer secret']
            && $request['max_tokens'] === 1
            && $request['model'] === 'ledgerly-support');
    }

    public function test_a_hosted_provider_is_not_something_the_probe_can_judge(): void
    {
        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'frontier'])
            ->expectsOutputToContain('is not a server this app can probe')
            ->assertFailed();
    }

    public function test_an_unknown_provider_is_reported(): void
    {
        $this->artisan('ai:doctor', ['--health' => true, '--provider' => 'nope'])
            ->expectsOutputToContain('No provider named [nope]')
            ->assertFailed();
    }

    private function serverAnswers(): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'ledgerly-support']]]),
            '*/chat/completions' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'length']],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 1],
            ]),
        ]);
    }
}
