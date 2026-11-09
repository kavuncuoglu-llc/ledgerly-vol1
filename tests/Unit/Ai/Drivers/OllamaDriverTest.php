<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\Failed;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\ModelCapabilities;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OllamaDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        WarnOnce::reset();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.ollama-test', [
            'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434/v1',
            'api_key' => 'ollama',
            'model' => 'ledgerly-support',
        ]);
    }

    public function test_a_chat_goes_to_the_native_endpoint_with_the_v1_suffix_removed(): void
    {
        $this->fakeAnswer('Hello there');

        $this->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ollama.test:11434/api/chat'
            && $request['model'] === 'ledgerly-support'
            && $request['stream'] === false
            && $request['messages'] === [['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_the_response_carries_exact_counts_the_cached_count_and_a_measured_first_token(): void
    {
        $this->fakeAnswer('Hello there', [
            'prompt_eval_count' => 563,
            'prompt_eval_cached_count' => 554,
            'eval_count' => 4,
            'load_duration' => 3_000_000,
            'prompt_eval_duration' => 120_000_000,
            'eval_duration' => 80_000_000,
        ]);

        $response = $this->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Hello there', $response->content);
        $this->assertSame(563, $response->usage->promptTokens);
        $this->assertSame(4, $response->usage->completionTokens);
        $this->assertSame(554, $response->usage->cachedTokens);
        $this->assertFalse($response->usage->estimated);
        $this->assertSame(143, $response->firstTokenMs);
    }

    public function test_the_logged_call_gets_the_servers_first_token_time_as_ttft(): void
    {
        $this->fakeAnswer('Hi', ['load_duration' => 1_000_000_000, 'prompt_eval_duration' => 500_000_000, 'eval_count' => 2, 'eval_duration' => 100_000_000]);

        $this->chat([['role' => 'user', 'content' => 'Hi']]);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->ttftMs === 1550
            && $event->usage['prompt_tokens'] === 16
            && $event->provider === 'ollama-test');
    }

    public function test_options_are_renamed_and_unsupported_ones_are_dropped_once(): void
    {
        Log::spy();
        $this->fakeAnswer('ok');

        $this->chat([['role' => 'user', 'content' => 'Hi']], ['temperature' => 0, 'top_p' => 0.9, 'seed' => 42, 'max_tokens' => 20, 'stop' => '###', 'logprobs' => true, 'profile' => null]);

        Http::assertSent(fn (Request $request): bool => $request['options'] === [
            'temperature' => 0,
            'top_p' => 0.9,
            'seed' => 42,
            'num_predict' => 20,
            'stop' => ['###'],
        ]);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_num_ctx_from_config_is_sent_on_every_request(): void
    {
        config()->set('ai.ollama.num_ctx', 8192);
        $this->fakeAnswer('ok');

        $this->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn (Request $request): bool => $request['options']['num_ctx'] === 8192);
    }

    public function test_the_context_window_override_wins_over_config_and_is_set_on_one_model_only(): void
    {
        config()->set('ai.ollama.num_ctx', 8192);
        $this->fakeAnswer('ok');
        $base = app(AiManager::class)->chat('ollama-test');
        $wide = ModelCapabilities::withContextWindow($base, 16384);
        $request = ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'test');

        $wide->chat($request);
        $base->chat($request);

        Http::assertSent(fn (Request $sent): bool => $sent['options']['num_ctx'] === 16384);
        Http::assertSent(fn (Request $sent): bool => $sent['options']['num_ctx'] === 8192);
    }

    public function test_keep_alive_defaults_to_the_apps_setting_and_a_provider_can_override_it(): void
    {
        $this->fakeAnswer('ok');

        $this->chat([['role' => 'user', 'content' => 'Hi']]);
        Http::assertSent(fn (Request $request): bool => $request['keep_alive'] === '30m');

        config()->set('ai.providers.ollama-test.keep_alive', '-1');
        app(AiManager::class)->forget();
        $this->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn (Request $request): bool => $request['keep_alive'] === -1);
    }

    public function test_no_keep_alive_is_sent_when_none_is_configured(): void
    {
        config()->set('ai.ollama.keep_alive', null);
        $this->fakeAnswer('ok');

        $this->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn (Request $request): bool => ! isset($request['keep_alive']));
    }

    public function test_a_json_schema_response_format_becomes_the_format_field(): void
    {
        $this->fakeAnswer('{"category":"billing"}');
        $schema = ['type' => 'object', 'properties' => ['category' => ['type' => 'string']], 'required' => ['category']];

        $this->chat([['role' => 'user', 'content' => 'Hi']], ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'ticket', 'schema' => $schema]]]);

        Http::assertSent(fn (Request $request): bool => $request['format'] === $schema);
    }

    public function test_tool_definitions_pass_through_and_tool_choice_is_dropped_with_a_warning(): void
    {
        Log::spy();
        $this->fakeAnswer('ok');
        $tools = [['type' => 'function', 'function' => ['name' => 'lookup_invoice', 'description' => 'Look up.', 'parameters' => ['type' => 'object']]]];

        $this->chat([['role' => 'user', 'content' => 'Hi']], ['tools' => $tools, 'tool_choice' => 'auto']);

        Http::assertSent(fn (Request $request): bool => $request['tools'] === $tools && ! isset($request['tool_choice']));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_tool_round_is_sent_with_decoded_arguments_and_the_tool_name(): void
    {
        $this->fakeAnswer('Overdue.');

        $this->chat([
            ['role' => 'user', 'content' => 'Status of 4471?'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"status":"overdue"}'],
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request['messages'][1]['tool_calls'][0]['function'] === ['name' => 'lookup_invoice', 'arguments' => ['invoice_number' => '4471']]
                && $request['messages'][2] === ['role' => 'tool', 'content' => '{"status":"overdue"}', 'tool_name' => 'lookup_invoice'];
        });
    }

    public function test_a_tool_call_without_arguments_is_sent_as_an_empty_object(): void
    {
        $this->fakeAnswer('ok');

        $this->chat([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_invoices', 'arguments' => '']]]],
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"arguments":{}'));
    }

    public function test_image_parts_become_a_base64_images_list_and_text_parts_become_content(): void
    {
        $this->fakeAnswer('ok');

        $this->chat([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Read this invoice.'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,QUJD']],
            ],
        ]]);

        Http::assertSent(fn (Request $request): bool => $request['messages'][0] === ['role' => 'user', 'content' => 'Read this invoice.', 'images' => ['QUJD']]);
    }

    public function test_an_image_url_that_is_not_a_data_url_is_refused_before_any_request(): void
    {
        Http::fake();

        $this->expectException(InvalidRequest::class);

        try {
            $this->chat([['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/a.jpg']]]]]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_tool_calls_come_back_in_the_shared_shape_with_json_string_arguments(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'ledgerly-support',
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_abc', 'function' => ['index' => 0, 'name' => 'lookup_invoice', 'arguments' => ['invoice_number' => '4471']]],
                ['function' => ['name' => 'list_invoices', 'arguments' => []]],
            ]],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 100,
            'eval_count' => 20,
        ])]);

        $response = $this->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertNull($response->content);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertSame([
            ['id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']],
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_invoices', 'arguments' => '{}']],
        ], $response->toolCalls);
    }

    public function test_a_body_without_a_message_is_malformed(): void
    {
        Http::fake(['*' => Http::response(['done' => true])]);

        $this->expectException(ModelRequestFailed::class);

        $this->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_a_stream_yields_deltas_then_completed_with_exact_counts_and_a_first_token_time(): void
    {
        Http::fake(['*' => Http::response($this->ndjson([
            ['model' => 'ledgerly-support', 'message' => ['role' => 'assistant', 'content' => 'Hel'], 'done' => false],
            ['model' => 'ledgerly-support', 'message' => ['role' => 'assistant', 'content' => 'lo'], 'done' => false],
            ['model' => 'ledgerly-support', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 30, 'prompt_eval_cached_count' => 20, 'eval_count' => 2, 'load_duration' => 2_000_000, 'prompt_eval_duration' => 40_000_000, 'eval_duration' => 20_000_000],
        ]))]);

        $stream = app(AiManager::class)->chat('ollama-test')->stream($this->request([['role' => 'user', 'content' => 'Hi']]));
        $events = iterator_to_array($stream, false);
        $response = $stream->getReturn();

        $this->assertSame(['Hel', 'lo'], array_map(fn (Delta $delta): string => $delta->text, array_values(array_filter($events, fn (mixed $event): bool => $event instanceof Delta))));
        $this->assertInstanceOf(Completed::class, end($events));
        $this->assertSame('Hello', $response->content);
        $this->assertSame(30, $response->usage->promptTokens);
        $this->assertSame(20, $response->usage->cachedTokens);
        $this->assertSame(2, $response->usage->completionTokens);
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->ttftMs !== null && $event->usage['completion_tokens'] === 2);
    }

    public function test_a_streamed_tool_call_is_announced_then_finishes_as_tool_calls_even_though_ollama_says_stop(): void
    {
        Http::fake(['*' => Http::response($this->ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_x', 'function' => ['name' => 'lookup_invoice', 'arguments' => ['invoice_number' => '4471']]]]], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 188, 'eval_count' => 19],
        ]))]);

        $stream = app(AiManager::class)->chat('ollama-test')->stream($this->request([['role' => 'user', 'content' => 'Hi']]));
        $events = iterator_to_array($stream, false);
        $response = $stream->getReturn();

        $this->assertEquals(new ToolCallStarted(0, 'lookup_invoice'), array_values(array_filter($events, fn (mixed $event): bool => $event instanceof ToolCallStarted))[0]);
        $this->assertEquals(new ToolCallArguments(0, '{"invoice_number":"4471"}'), array_values(array_filter($events, fn (mixed $event): bool => $event instanceof ToolCallArguments))[0]);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertSame('call_x', $response->toolCalls[0]['id']);
    }

    public function test_a_stream_that_ends_before_the_final_message_fails_with_what_arrived(): void
    {
        Http::fake(['*' => Http::response($this->ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Part'], 'done' => false],
        ]))]);

        $stream = app(AiManager::class)->chat('ollama-test')->stream($this->request([['role' => 'user', 'content' => 'Hi']]));
        $seen = [];

        try {
            foreach ($stream as $event) {
                $seen[] = $event;
            }

            $this->fail('Expected StreamTruncated');
        } catch (StreamTruncated $exception) {
            $this->assertSame('Part', $exception->partialContent);
        }

        $this->assertInstanceOf(Failed::class, end($seen));
        $this->assertSame(FinishReason::Truncated, end($seen)->partial->finishReason);
    }

    public function test_an_error_line_in_the_stream_is_raised(): void
    {
        Http::fake(['*' => Http::response($this->ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Part'], 'done' => false],
            ['error' => 'llama runner process has terminated'],
        ]))]);

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('llama runner process has terminated');

        iterator_to_array(app(AiManager::class)->chat('ollama-test')->stream($this->request([['role' => 'user', 'content' => 'Hi']])), false);
    }

    public function test_a_missing_model_is_an_invalid_request_not_a_retry(): void
    {
        Http::fake(['*' => Http::response(['error' => "model 'nope' not found"], 404)]);

        try {
            $this->chat([['role' => 'user', 'content' => 'Hi']]);

            $this->fail('Expected InvalidRequest');
        } catch (InvalidRequest $exception) {
            $this->assertStringContainsString("model 'nope' not found", $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_raw_sends_openai_style_paths_to_v1_and_native_paths_as_they_are(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'ledgerly-support']]])]);
        $model = app(AiManager::class)->chat('ollama-test');

        $served = $model->raw('GET', '/models');
        $model->raw('GET', '/api/tags');

        $this->assertSame('ledgerly-support', $served['data'][0]['id']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ollama.test:11434/v1/models');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ollama.test:11434/api/tags');
    }

    public function test_it_reports_tools_structured_output_and_seed_and_vision_only_when_configured(): void
    {
        $model = app(AiManager::class)->chat('ollama-test');

        $this->assertTrue($model->supports(Capability::Tools));
        $this->assertTrue($model->supports(Capability::StructuredOutput));
        $this->assertTrue($model->supports(Capability::Seed));
        $this->assertFalse($model->supports(Capability::Vision));
        $this->assertFalse($model->supports(Capability::CacheBreakpoints));
    }

    private function chat(array $messages, array $options = []): ChatResponse
    {
        return app(AiManager::class)->chat('ollama-test')->chat($this->request($messages, $options));
    }

    private function request(array $messages, array $options = []): ChatRequest
    {
        return ChatRequest::fromOptions($messages, ['profile' => null, ...$options], 'test');
    }

    private function fakeAnswer(string $content, array $extra = []): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'ledgerly-support',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 16,
            'eval_count' => 3,
            ...$extra,
        ])]);
    }

    private function ndjson(array $lines): string
    {
        return implode("\n", array_map(fn (array $line): string => (string) json_encode($line), $lines))."\n";
    }
}
