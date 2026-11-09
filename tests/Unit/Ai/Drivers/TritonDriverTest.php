<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\TritonDriver;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\ModelCapabilities;
use App\Ai\Native\TritonEndpoint;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHttp;
use Tests\TestCase;

class TritonDriverTest extends TestCase
{
    private const MESSAGES = [
        [
            'role' => 'system',
            'content' => 'Classify.',
        ],
        [
            'role' => 'user',
            'content' => 'Why was I charged twice?',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.triton-test', [
            'driver' => 'triton',
            'base_url' => 'http://triton.test:9000/v1',
            'api_key' => 'secret-key',
            'auth_header' => 'x-api-key',
            'model' => 'chat',
            'default_max_tokens' => 321,
            'capabilities' => ['tools', 'seed'],
            'unsupported_options' => ['min_p', 'top_k', 'repeat_penalty', 'num_ctx'],
        ]);
    }

    public function test_a_chat_goes_to_chat_completions_with_the_configured_header_and_no_bearer_token(): void
    {
        FakeHttp::replay('triton', 'short-answer');

        $response = $this->driver()->chat($this->request());

        $this->assertSame('duplicate', $response->content);
        $this->assertSame(35, $response->usage->promptTokens);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://triton.test:9000/v1/chat/completions'
            && $request->header('x-api-key') === ['secret-key']
            && ! $request->hasHeader('Authorization')
            && $request['model'] === 'chat'
            && $request['stream'] === false);
    }

    public function test_a_request_with_no_token_limit_gets_the_providers_default_because_the_frontend_would_stop_at_sixteen(): void
    {
        FakeHttp::replay('triton', 'short-answer');

        $this->driver()->chat($this->request());

        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 321);
    }

    public function test_an_explicit_token_limit_is_left_alone(): void
    {
        FakeHttp::replay('triton', 'short-answer');

        $this->driver()->chat($this->request(['max_tokens' => 50]));

        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 50);
    }

    public function test_max_completion_tokens_counts_as_a_limit(): void
    {
        FakeHttp::replay('triton', 'short-answer');

        $this->driver()->chat($this->request(['max_completion_tokens' => 70]));

        Http::assertSent(fn (Request $request): bool => $request['max_completion_tokens'] === 70 && ! isset($request['max_tokens']));
    }

    public function test_options_the_frontend_would_reject_are_dropped_because_its_request_model_forbids_extra_fields(): void
    {
        FakeHttp::replay('triton', 'short-answer');

        $this->driver()->chat($this->request(['top_k' => 40, 'min_p' => 0.05, 'repeat_penalty' => 1.1, 'temperature' => 0.2]));

        Http::assertSent(fn (Request $request): bool => ! isset($request['top_k'])
            && ! isset($request['min_p'])
            && ! isset($request['repeat_penalty'])
            && $request['temperature'] === 0.2);
    }

    public function test_an_unknown_model_is_an_invalid_request_after_one_attempt(): void
    {
        FakeHttp::replay('triton', 'unknown-model');

        try {
            $this->driver()->chat($this->request());

            $this->fail('Expected InvalidRequest');
        } catch (InvalidRequest $exception) {
            $this->assertStringContainsString('Unknown model: ledgerly-support', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_a_422_for_an_unexpected_field_is_an_invalid_request(): void
    {
        FakeHttp::replay('triton', 'unexpected-field');

        $this->expectException(InvalidRequest::class);

        $this->driver()->chat($this->request());
    }

    public function test_a_500_from_the_frontend_is_retryable_provider_unavailability(): void
    {
        FakeHttp::replay('triton', 'overloaded');

        $this->expectException(ProviderUnavailable::class);

        $this->driver()->chat($this->request());
    }

    public function test_a_stream_yields_the_text_and_the_usage_from_the_final_chunk(): void
    {
        FakeHttp::replay('triton', 'stream-short-answer');

        $stream = $this->driver()->stream($this->request());

        $text = '';

        foreach ($stream as $event) {
            $text .= $event->text ?? '';
        }

        $response = $stream->getReturn();

        $this->assertSame('duplicate', $text);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(35, $response->usage->promptTokens);
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true
            && $request['stream_options'] === ['include_usage' => true]
            && $request['max_tokens'] === 321);
    }

    public function test_it_declares_tools_and_seed_but_not_structured_output_or_vision(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->supports(Capability::Tools));
        $this->assertTrue($driver->supports(Capability::Seed));
        $this->assertFalse($driver->supports(Capability::StructuredOutput));
        $this->assertFalse($driver->supports(Capability::Vision));
    }

    public function test_it_has_no_per_request_context_window_and_no_grammar(): void
    {
        $model = app(AiManager::class)->chat('triton-test');

        $this->assertFalse(ModelCapabilities::has($model, SupportsContextWindowOverride::class));
        $this->assertFalse(ModelCapabilities::has($model, SupportsGrammar::class));
    }

    public function test_the_manager_builds_the_triton_driver_and_logs_the_provider_name(): void
    {
        FakeHttp::replay('triton', 'short-answer');

        app(AiManager::class)->chat('triton-test')->chat($this->request());

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === 'triton-test');
    }

    public function test_the_shipped_provider_config_declares_the_frontends_limits(): void
    {
        $config = config('ai.providers.triton');

        $this->assertSame('triton', $config['driver']);
        $this->assertSame('http://127.0.0.1:9000/v1', $config['base_url']);
        $this->assertNotContains('structured', $config['capabilities']);
        $this->assertContains('top_k', $config['unsupported_options']);
        $this->assertContains('num_ctx', $config['unsupported_options']);
    }

    public function test_no_auth_header_is_sent_when_none_is_configured(): void
    {
        $this->assertSame([], TritonEndpoint::authHeaders(['auth_header' => '', 'api_key' => 'x']));
        $this->assertSame([], TritonEndpoint::authHeaders(['auth_header' => 'x-api-key', 'api_key' => '']));
        $this->assertSame(['x-api-key' => 'k'], TritonEndpoint::authHeaders(['auth_header' => 'x-api-key', 'api_key' => 'k']));
    }

    public function test_the_local_role_follows_ai_local_provider_triton(): void
    {
        $this->assertTrue(TritonEndpoint::isTriton(['driver' => 'triton']));
        $this->assertTrue(TritonEndpoint::isTriton(['driver' => 'openai-compatible', 'triton' => true]));
        $this->assertFalse(TritonEndpoint::isTriton(['driver' => 'vllm']));
        $this->assertSame('http://triton:9000', TritonEndpoint::nativeBaseUrl('http://triton:9000/v1/'));
    }

    private function driver(): TritonDriver
    {
        return new TritonDriver(
            app(AiManager::class)->providerConfig('triton-test'),
            app(TokenCounter::class),
            app(DebugLog::class),
        );
    }

    private function request(array $options = []): ChatRequest
    {
        return new ChatRequest(messages: self::MESSAGES, feature: 'test', options: $options, profile: ChatRequest::NO_PROFILE);
    }
}
