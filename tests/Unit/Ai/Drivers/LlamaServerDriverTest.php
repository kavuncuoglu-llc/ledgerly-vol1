<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Drivers\LlamaServerDriver;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\ModelCapabilities;
use App\Ai\Prompts\TicketClassifyPrompt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHttp;
use Tests\TestCase;

class LlamaServerDriverTest extends TestCase
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

        config()->set('ai.providers.llama-test', [
            'driver' => 'llama-server',
            'base_url' => 'http://llama.test:8080/v1',
            'api_key' => 'secret-key',
            'model' => 'ledgerly-support',
        ]);
    }

    public function test_a_plain_chat_goes_to_the_compatible_endpoint_with_the_bearer_key(): void
    {
        FakeHttp::replay('llama-server', 'short-answer');

        $response = $this->driver()->chat($this->request());

        $this->assertSame('duplicate', $response->content);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://llama.test:8080/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer secret-key']
            && $request['model'] === 'ledgerly-support'
            && $request['stream'] === false);
    }

    public function test_the_servers_timings_become_the_first_token_time(): void
    {
        Http::fake(['*' => Http::response($this->completionWithTimings(promptMs: 640.5, predictedPerTokenMs: 18.9))]);

        $response = $this->driver()->chat($this->request());

        $this->assertSame(659, $response->firstTokenMs);
    }

    public function test_a_response_without_timings_has_no_first_token_time(): void
    {
        $body = FakeHttp::load('llama-server', 'short-answer')['response']['body'];

        Http::fake(['*' => Http::response(array_diff_key(json_decode($body, true), ['timings' => true]))]);

        $this->assertNull($this->driver()->chat($this->request())->firstTokenMs);
    }

    public function test_a_prompt_that_does_not_fit_the_slot_is_a_context_too_long_error(): void
    {
        FakeHttp::replay('llama-server', 'context-overflow');

        try {
            $this->driver()->chat($this->request());

            $this->fail('Expected ContextTooLong');
        } catch (ContextTooLong $exception) {
            $this->assertSame(400, $exception->status);
            $this->assertStringContainsString('exceeds the available context size (8192 tokens)', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_the_driver_does_not_claim_the_per_request_context_override(): void
    {
        $this->assertNotInstanceOf(SupportsContextWindowOverride::class, $this->driver());
        $this->assertTrue($this->driver()->supports(Capability::Tools));
        $this->assertTrue($this->driver()->supports(Capability::StructuredOutput));
        $this->assertFalse($this->driver()->supports(Capability::Vision));
    }

    public function test_with_grammar_renders_the_prompt_on_the_server_then_completes_under_the_grammar(): void
    {
        $this->fakeGrammarExchange(content: 'billing');

        $this->driver()
            ->withGrammar('root ::= "billing" | "other"')
            ->chat($this->request(options: ['max_tokens' => 8, 'temperature' => 0, 'seed' => 42]));

        Http::assertSentInOrder([
            fn (Request $request): bool => $request->url() === 'http://llama.test:8080/apply-template'
                && $request->header('Authorization') === ['Bearer secret-key']
                && $request['messages'] === self::MESSAGES,
            fn (Request $request): bool => $request->url() === 'http://llama.test:8080/completion'
                && $request->header('Authorization') === ['Bearer secret-key']
                && $request['prompt'] === '<|start_header_id|>system<|end_header_id|>rendered'
                && $request['grammar'] === 'root ::= "billing" | "other"'
                && $request['n_predict'] === 8
                && $request['temperature'] === 0
                && $request['seed'] === 42
                && $request['stream'] === false
                && $request['cache_prompt'] === true
                && ! isset($request['max_tokens'])
                && ! isset($request['messages']),
        ]);
    }

    public function test_a_grammar_reply_maps_content_usage_cache_and_finish_reason(): void
    {
        $this->fakeGrammarExchange(content: 'billing', extra: [
            'tokens_evaluated' => 120,
            'tokens_predicted' => 2,
            'tokens_cached' => 100,
            'timings' => ['prompt_ms' => 40.0, 'predicted_per_token_ms' => 12.0],
        ]);

        $response = $this->driver()->withGrammar('root ::= "billing"')->chat($this->request());

        $this->assertSame('billing', $response->content);
        $this->assertSame([], $response->toolCalls);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(120, $response->usage->promptTokens);
        $this->assertSame(2, $response->usage->completionTokens);
        $this->assertSame(100, $response->usage->cachedTokens);
        $this->assertFalse($response->usage->estimated);
        $this->assertSame(52, $response->firstTokenMs);
    }

    public function test_hitting_the_token_limit_inside_a_grammar_is_a_length_stop(): void
    {
        $this->fakeGrammarExchange(content: 'bill', extra: ['stop_type' => 'limit']);

        $response = $this->driver()->withGrammar('root ::= "billing"')->chat($this->request());

        $this->assertSame(FinishReason::Length, $response->finishReason);
    }

    public function test_with_grammar_leaves_the_original_driver_unconstrained(): void
    {
        $driver = $this->driver();

        $constrained = $driver->withGrammar('root ::= "ok"');

        $this->assertNotSame($driver, $constrained);
        $this->assertInstanceOf(SupportsGrammar::class, $constrained);

        FakeHttp::replay('llama-server', 'short-answer');

        $driver->chat($this->request());

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/chat/completions'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/completion'));
    }

    public function test_a_grammar_cannot_be_combined_with_tools_or_a_response_format(): void
    {
        $this->fakeGrammarExchange(content: 'billing');

        $constrained = $this->driver()->withGrammar('root ::= "billing"');

        $withTools = new ChatRequest(messages: self::MESSAGES, feature: 'test', tools: [['type' => 'function']]);

        $withFormat = new ChatRequest(messages: self::MESSAGES, feature: 'test', responseFormat: ['type' => 'json_object']);

        foreach ([$withTools, $withFormat] as $request) {
            try {
                $constrained->chat($request);

                $this->fail('Expected InvalidRequest');
            } catch (InvalidRequest) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_template_answer_without_a_prompt_is_a_malformed_reply(): void
    {
        Http::fake(['*/apply-template' => Http::response(['unexpected' => true])]);

        $this->expectException(ModelRequestFailed::class);

        $this->driver()->withGrammar('root ::= "billing"')->chat($this->request());
    }

    public function test_a_completion_answer_without_content_is_a_malformed_reply(): void
    {
        Http::fake([
            '*/apply-template' => Http::response(['prompt' => 'rendered']),
            '*/completion' => Http::response(['stop_type' => 'eos']),
        ]);

        $this->expectException(ModelRequestFailed::class);

        $this->driver()->withGrammar('root ::= "billing"')->chat($this->request());
    }

    public function test_a_constrained_stream_yields_the_whole_answer_then_completed(): void
    {
        $this->fakeGrammarExchange(content: 'howto');

        $stream = $this->driver()->withGrammar('root ::= "howto"')->stream($this->request());

        $events = iterator_to_array($stream, false);

        $this->assertEquals([new Delta('howto'), new Completed($stream->getReturn())], $events);
        $this->assertSame('howto', $stream->getReturn()->content);
    }

    public function test_an_unconstrained_stream_is_the_compatible_stream(): void
    {
        FakeHttp::replay('llama-server', 'stream-short-answer');

        $stream = $this->driver()->stream($this->request());

        $events = iterator_to_array($stream, false);

        $this->assertSame('duplicate', $stream->getReturn()->content);
        $this->assertInstanceOf(Completed::class, end($events));
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true);
    }

    public function test_the_manager_builds_the_driver_and_the_capability_survives_the_decorators(): void
    {
        $model = app(AiManager::class)->chat('llama-test');

        $this->assertTrue(ModelCapabilities::has($model, SupportsGrammar::class));
        $this->assertFalse(ModelCapabilities::has($model, SupportsContextWindowOverride::class));
        $this->assertFalse(ModelCapabilities::has(app(AiManager::class)->chat('local'), SupportsGrammar::class));
    }

    public function test_a_grammar_call_through_the_decorated_model_is_logged_like_any_other_call(): void
    {
        $this->fakeGrammarExchange(content: 'bug');

        $model = ModelCapabilities::withGrammar(app(AiManager::class)->chat('llama-test'), TicketClassifyPrompt::grammar());

        $response = $model->chat($this->request(feature: 'ticket.classify'));

        $this->assertSame('bug', $response->content);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === 'llama-test'
            && $event->feature === 'ticket.classify');
    }

    public function test_the_context_override_is_a_no_op_for_llama_server(): void
    {
        $model = app(AiManager::class)->chat('llama-test');

        $this->assertEquals($model, ModelCapabilities::withContextWindow($model, 16384));
    }

    private function driver(): LlamaServerDriver
    {
        $driver = app(AiManager::class)->chat('llama-test');

        while (! $driver instanceof LlamaServerDriver) {
            $driver = $driver->inner();
        }

        return $driver;
    }

    private function request(array $options = [], string $feature = 'test'): ChatRequest
    {
        return ChatRequest::fromOptions(
            messages: self::MESSAGES,
            options: [
                'profile' => null,
                ...$options,
            ],
            feature: $feature,
        );
    }

    private function fakeGrammarExchange(string $content, array $extra = []): void
    {
        Http::fake([
            '*/apply-template' => Http::response(['prompt' => '<|start_header_id|>system<|end_header_id|>rendered']),
            '*/completion' => Http::response([
                'content' => $content,
                'stop_type' => 'eos',
                'tokens_evaluated' => 30,
                'tokens_predicted' => 1,
                'tokens_cached' => 0,
                ...$extra,
            ]),
        ]);
    }

    private function completionWithTimings(float $promptMs, float $predictedPerTokenMs): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'ledgerly-support',
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'ok',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 1,
            ],
            'timings' => [
                'prompt_ms' => $promptMs,
                'predicted_per_token_ms' => $predictedPerTokenMs,
            ],
        ];
    }
}
