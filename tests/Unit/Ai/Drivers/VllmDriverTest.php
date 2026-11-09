<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\VllmDriver;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\ModelCapabilities;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHttp;
use Tests\TestCase;

class VllmDriverTest extends TestCase
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

        config()->set('ai.providers.vllm-test', [
            'driver' => 'vllm',
            'base_url' => 'http://vllm.test:8000/v1',
            'api_key' => 'secret-key',
            'model' => 'ledgerly-support',
            'option_renames' => [
                'repeat_penalty' => 'repetition_penalty',
            ],
        ]);
    }

    public function test_a_chat_goes_to_chat_completions_with_the_bearer_key_and_the_served_alias(): void
    {
        FakeHttp::replay('vllm', 'short-answer');

        $response = $this->driver()->chat($this->request());

        $this->assertSame('duplicate', $response->content);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://vllm.test:8000/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer secret-key']
            && $request['model'] === 'ledgerly-support'
            && $request['stream'] === false);
    }

    public function test_an_over_length_prompt_is_a_context_too_long_after_one_attempt(): void
    {
        FakeHttp::replay('vllm', 'context-overflow');

        $this->expectException(ContextTooLong::class);

        $this->driver()->chat($this->request());
    }

    public function test_the_other_context_message_vllm_raises_for_a_character_limit_is_also_context_too_long(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => "This model's maximum context length is 8192 tokens. However, your prompt contains 99999 characters (more than 40000 characters, which is the upper bound for 8192 input tokens). Please reduce the length of the input prompt.", 'type' => 'BadRequestError', 'param' => 'input_text', 'code' => 400]], 400)]);

        $this->expectException(ContextTooLong::class);

        $this->driver()->chat($this->request());
    }

    public function test_any_other_bad_request_stays_an_invalid_request(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'max_tokens=9000 cannot be greater than max_model_len=8192. Please request fewer output tokens.', 'type' => 'BadRequestError', 'param' => 'max_tokens', 'code' => 400]], 400)]);

        $this->expectException(InvalidRequest::class);

        $this->driver()->chat($this->request());
    }

    public function test_a_503_is_retryable(): void
    {
        FakeHttp::replay('vllm', 'overloaded');

        $this->expectException(ProviderUnavailable::class);

        $this->driver()->chat($this->request());
    }

    public function test_the_authentication_middleware_body_is_a_plain_string_and_still_maps(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectException(InvalidRequest::class);

        $this->driver()->chat($this->request());
    }

    public function test_repeat_penalty_is_sent_under_vllms_name(): void
    {
        FakeHttp::replay('vllm', 'short-answer');

        $this->driver()->chat($this->request(['repeat_penalty' => 1.1, 'top_k' => 40, 'min_p' => 0.05]));

        Http::assertSent(fn (Request $request): bool => $request['repetition_penalty'] === 1.1
            && ! isset($request['repeat_penalty'])
            && $request['top_k'] === 40
            && $request['min_p'] === 0.05);
    }

    public function test_a_response_format_is_passed_through_for_guided_decoding(): void
    {
        FakeHttp::replay('vllm', 'structured');

        $schema = [
            'type' => 'json_schema',
            'json_schema' => ['name' => 'ticket_category', 'strict' => true, 'schema' => ['type' => 'object']],
        ];

        $response = $this->driver()->chat(new ChatRequest(messages: self::MESSAGES, feature: 'test', responseFormat: $schema));

        $this->assertSame(['category' => 'billing'], json_decode((string) $response->content, true));
        Http::assertSent(fn (Request $request): bool => $request['response_format'] === $schema);
    }

    public function test_cached_tokens_reported_with_prompt_tokens_details_land_in_usage(): void
    {
        FakeHttp::replay('vllm', 'cached');

        $response = $this->driver()->chat($this->request());

        $this->assertSame(2048, $response->usage->cachedTokens);
    }

    public function test_cached_tokens_stay_unknown_when_the_server_leaves_prompt_tokens_details_null(): void
    {
        FakeHttp::replay('vllm', 'short-answer');

        $response = $this->driver()->chat($this->request());

        $this->assertNull($response->usage->cachedTokens);
    }

    public function test_a_stream_yields_the_text_and_the_usage_from_the_final_chunk(): void
    {
        FakeHttp::replay('vllm', 'stream-short-answer');

        $stream = $this->driver()->stream($this->request());

        $text = '';

        foreach ($stream as $event) {
            $text .= $event->text ?? '';
        }

        $response = $stream->getReturn();

        $this->assertSame('duplicate', $text);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(35, $response->usage->promptTokens);
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true && $request['stream_options'] === ['include_usage' => true]);
    }

    public function test_it_declares_tools_structured_output_and_seed_but_not_vision(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->supports(Capability::Tools));
        $this->assertTrue($driver->supports(Capability::StructuredOutput));
        $this->assertTrue($driver->supports(Capability::Seed));
        $this->assertFalse($driver->supports(Capability::Vision));
    }

    public function test_it_has_no_per_request_context_window_and_no_grammar(): void
    {
        $model = app(AiManager::class)->chat('vllm-test');

        $this->assertFalse(ModelCapabilities::has($model, SupportsContextWindowOverride::class));
        $this->assertFalse(ModelCapabilities::has($model, SupportsGrammar::class));
    }

    public function test_a_context_window_override_leaves_the_request_untouched(): void
    {
        FakeHttp::replay('vllm', 'short-answer');

        $model = ModelCapabilities::withContextWindow(app(AiManager::class)->chat('vllm-test'), 16384);

        $model->chat($this->request());

        Http::assertSent(fn (Request $request): bool => ! isset($request['num_ctx']));
    }

    public function test_the_manager_builds_the_vllm_driver_and_logs_the_provider_name(): void
    {
        FakeHttp::replay('vllm', 'short-answer');

        app(AiManager::class)->chat('vllm-test')->chat($this->request());

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === 'vllm-test');
    }

    private function driver(): VllmDriver
    {
        return new VllmDriver(
            app(AiManager::class)->providerConfig('vllm-test'),
            app(TokenCounter::class),
            app(DebugLog::class),
        );
    }

    private function request(array $options = []): ChatRequest
    {
        return new ChatRequest(messages: self::MESSAGES, feature: 'test', options: $options, profile: ChatRequest::NO_PROFILE);
    }
}
