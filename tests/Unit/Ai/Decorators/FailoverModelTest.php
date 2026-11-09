<?php

namespace Tests\Unit\Ai\Decorators;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Decorators\FailoverModel;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ScriptedModel;
use Tests\TestCase;

class FailoverModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);
    }

    public function test_it_answers_from_the_primary_when_the_primary_is_up(): void
    {
        $primary = ScriptedModel::answering('vllm', 'from vllm');
        $fallback = ScriptedModel::answering('frontier', 'from frontier');

        $model = new FailoverModel($primary, fn () => $fallback, 'frontier');

        $this->assertSame('from vllm', $model->chat($this->request())->content);
        $this->assertSame(0, $fallback->calls);
    }

    public function test_it_answers_from_the_fallback_when_the_primary_is_unavailable_and_drops_a_primary_only_model_name(): void
    {
        $primary = new ScriptedModel('vllm', [new ProviderUnavailable('down')]);
        $fallback = ScriptedModel::answering('frontier', 'from frontier');

        $model = new FailoverModel($primary, fn () => $fallback, 'frontier');

        $response = $model->chat($this->request()->withModel('support-adapter'));

        $this->assertSame('from frontier', $response->content);
        $this->assertNull($fallback->received[0]->model);
    }

    public function test_other_errors_do_not_fail_over(): void
    {
        $primary = new ScriptedModel('vllm', [new InvalidRequest('bad request')]);
        $fallback = ScriptedModel::answering('frontier', 'from frontier');

        $model = new FailoverModel($primary, fn () => $fallback, 'frontier');

        $this->assertThrows(fn () => $model->chat($this->request()), InvalidRequest::class);
        $this->assertSame(0, $fallback->calls);
    }

    public function test_a_stream_that_never_started_fails_over(): void
    {
        $primary = new ScriptedModel('vllm', [new ProviderUnavailable('down')]);
        $fallback = ScriptedModel::answering('frontier', 'from frontier');

        $model = new FailoverModel($primary, fn () => $fallback, 'frontier');

        $stream = $model->stream($this->request());

        $events = iterator_to_array($stream, false);

        $this->assertSame('from frontier', $events[0]->text);
        $this->assertSame('from frontier', $stream->getReturn()->content);
    }

    public function test_the_manager_wraps_a_provider_with_a_configured_fallback_and_logs_the_provider_that_answered(): void
    {
        $this->configureProviders();

        config()->set('ai.failover', ['primary-down' => 'backup']);

        Http::fake([
            'http://primary.test/*' => Http::response('down', 503),
            'http://backup.test/*' => Http::response($this->completion('from backup')),
        ]);

        $model = app(AiManager::class)->chat('primary-down');

        $this->assertInstanceOf(FailoverModel::class, $model);
        $this->assertSame('from backup', $model->chat($this->request())->content);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === 'backup');
        Event::assertNotDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === 'primary-down');
    }

    public function test_once_the_breaker_is_open_the_primary_is_not_contacted_at_all(): void
    {
        $this->configureProviders();

        config()->set('ai.failover', ['primary-down' => 'backup']);
        config()->set('ai.breaker', ['enabled' => true, 'threshold' => 3, 'open_seconds' => 60, 'memory_seconds' => 600, 'store' => null]);

        Http::fake([
            'http://primary.test/*' => Http::response('down', 503),
            'http://backup.test/*' => Http::response($this->completion('from backup')),
        ]);

        $model = app(AiManager::class)->chat('primary-down');

        foreach (range(1, 3) as $ignored) {
            $model->chat($this->request());
        }

        $this->assertTrue((new CircuitBreaker)->isOpen('primary-down'));

        Http::fake([
            'http://primary.test/*' => Http::response('down', 503),
            'http://backup.test/*' => Http::response($this->completion('from backup')),
        ]);

        $this->assertSame('from backup', $model->chat($this->request())->content);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'http://primary.test'));
    }

    public function test_a_fallback_that_is_missing_unknown_or_the_provider_itself_is_ignored(): void
    {
        $this->configureProviders();

        $ai = app(AiManager::class);

        config()->set('ai.failover', ['primary-down' => 'primary-down']);
        $this->assertSame('', $ai->failoverTarget('primary-down'));

        config()->set('ai.failover', ['primary-down' => 'no-such-provider']);
        $this->assertSame('', $ai->failoverTarget('primary-down'));

        config()->set('ai.failover', ['primary-down' => 'unkeyed']);
        $this->assertSame('', $ai->failoverTarget('primary-down'));

        config()->set('ai.failover', []);
        $this->assertSame('', $ai->failoverTarget('primary-down'));
    }

    public function test_the_fallback_is_a_single_hop_so_two_providers_naming_each_other_cannot_loop(): void
    {
        $this->configureProviders();

        config()->set('ai.failover', ['primary-down' => 'backup', 'backup' => 'primary-down']);

        Http::fake(['*' => Http::response('down', 503)]);

        $this->assertThrows(fn () => app(AiManager::class)->chat('primary-down')->chat($this->request()), ProviderUnavailable::class);
    }

    public function test_chat_without_failover_returns_the_plain_decorated_model(): void
    {
        $this->configureProviders();

        config()->set('ai.failover', ['primary-down' => 'backup']);

        $this->assertNotInstanceOf(FailoverModel::class, app(AiManager::class)->chatWithoutFailover('primary-down'));
    }

    private function configureProviders(): void
    {
        config()->set('ai.providers.primary-down', [
            'driver' => 'openai-compatible',
            'base_url' => 'http://primary.test/v1',
            'api_key' => 'key',
            'model' => 'primary-model',
        ]);
        config()->set('ai.providers.backup', [
            'driver' => 'openai-compatible',
            'base_url' => 'http://backup.test/v1',
            'api_key' => 'key',
            'model' => 'backup-model',
        ]);
        config()->set('ai.providers.unkeyed', [
            'driver' => 'openai-compatible',
            'base_url' => 'http://unkeyed.test/v1',
            'model' => 'unkeyed-model',
        ]);

        app(AiManager::class)->forget();
    }

    private function completion(string $content): array
    {
        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'backup-model',
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => $content],
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ];
    }

    private function request(): ChatRequest
    {
        return new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'test', profile: ChatRequest::NO_PROFILE);
    }
}
