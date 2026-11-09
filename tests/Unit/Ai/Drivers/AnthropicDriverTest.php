<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Events\ModelCalled;
use App\Ai\ModelCapabilities;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeHttp;
use Tests\TestCase;

class AnthropicDriverTest extends TestCase
{
    private bool $replayed = false;

    protected function setUp(): void
    {
        parent::setUp();

        WarnOnce::reset();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.claude', [
            'driver' => 'anthropic',
            'base_url' => 'http://anthropic.test',
            'api_key' => 'sk-test',
            'model' => 'claude-test',
            'max_tokens' => 321,
        ]);

    }

    public function test_it_posts_to_the_messages_endpoint_with_the_anthropic_headers(): void
    {
        $this->send($this->request([$this->user('Hi')]));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://anthropic.test/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && ! $request->hasHeader('Authorization')
            && $request['model'] === 'claude-test');
    }

    public function test_system_messages_move_to_the_top_level_system_field(): void
    {
        $this->send($this->request([
            ['role' => 'system', 'content' => 'You are terse.'],
            $this->user('Hi'),
        ]));

        Http::assertSent(fn (Request $request): bool => $request['system'] === [['type' => 'text', 'text' => 'You are terse.']]
            && count($request['messages']) === 1
            && $request['messages'][0] === ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]);
    }

    public function test_max_tokens_defaults_from_config_and_can_be_overridden(): void
    {
        $this->send($this->request([$this->user('Hi')]));
        $this->send($this->request([$this->user('Hi')], ['max_tokens' => 50]));

        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 321);
        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 50);
    }

    public function test_options_are_renamed_or_dropped_with_a_single_warning(): void
    {
        Log::spy();

        $options = ['stop' => 'END', 'seed' => 42, 'min_p' => 0.1, 'temperature' => 0.2, 'top_p' => 0.9];
        $this->send($this->request([$this->user('Hi')], $options));
        $this->send($this->request([$this->user('Hi')], $options));

        Http::assertSent(fn (Request $request): bool => $request['stop_sequences'] === ['END']
            && $request['temperature'] === 0.2
            && ! isset($request['seed'])
            && ! isset($request['min_p'])
            && ! isset($request['stop']));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['option'] === 'seed')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['option'] === 'min_p')->once();
    }

    public function test_tools_become_input_schemas_and_tool_choice_is_translated(): void
    {
        $prompt = FakeHttp::prompt('tool-call');

        $this->send(new ChatRequest(
            messages: $prompt['messages'],
            feature: 'test',
            tools: $prompt['tools'],
            toolChoice: 'required',
            profile: ChatRequest::NO_PROFILE,
        ));

        Http::assertSent(fn (Request $request): bool => $request['tools'][0]['name'] === 'lookup_invoice'
            && $request['tools'][0]['input_schema']['required'] === ['invoice_number']
            && ! isset($request['tools'][0]['function'])
            && $request['tool_choice'] === ['type' => 'any']);
    }

    public function test_tool_calls_and_tool_results_round_trip_into_content_blocks(): void
    {
        $this->send($this->request([
            $this->user('Status of 4471?'),
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']],
                    ['id' => 'toolu_2', 'type' => 'function', 'function' => ['name' => 'account_status', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"status":"overdue"}'],
            ['role' => 'tool', 'tool_call_id' => 'toolu_2', 'content' => '{"plan":"pro"}'],
        ]));

        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];

            return count($messages) === 3
                && $messages[1]['role'] === 'assistant'
                && $messages[1]['content'][0] === ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup_invoice', 'input' => ['invoice_number' => '4471']]
                && $messages[2]['role'] === 'user'
                && $messages[2]['content'] === [
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"status":"overdue"}'],
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => '{"plan":"pro"}'],
                ];
        });
    }

    public function test_empty_tool_arguments_are_sent_as_an_object_not_a_list(): void
    {
        $this->send($this->request([
            $this->user('Status?'),
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'account_status', 'arguments' => '']]]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => 'ok'],
        ]));

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"input":{}'));
    }

    public function test_a_tool_use_block_with_no_input_comes_back_as_an_empty_json_object(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_1',
            'model' => 'claude-test',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'account_status', 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ])]);
        $this->replayed = true;

        $response = $this->send($this->request([$this->user('Status?')]));

        $this->assertSame('{}', $response->toolCalls[0]['function']['arguments']);
    }

    public function test_cache_breakpoints_mark_the_system_block_and_the_last_block_of_a_later_message(): void
    {
        $this->send(new ChatRequest(
            messages: [
                ['role' => 'system', 'content' => 'Stable instructions.'],
                ['role' => 'system', 'content' => 'Summary so far.'],
                $this->user('Question'),
            ],
            feature: 'test',
            cacheBreakpointsAfter: [1],
            profile: ChatRequest::NO_PROFILE,
        ));

        Http::assertSent(fn (Request $request): bool => ! isset($request['system'][0]['cache_control'])
            && $request['system'][1]['cache_control'] === ['type' => 'ephemeral']);
    }

    public function test_an_image_data_url_becomes_a_base64_image_block(): void
    {
        $this->send($this->request([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Read this invoice'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']],
                ],
            ],
        ]));

        Http::assertSent(fn (Request $request): bool => $request['messages'][0]['content'][1] === [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'QUJD'],
        ]);
    }

    public function test_a_response_format_becomes_a_forced_tool_call_by_default(): void
    {
        $this->replay('structured');

        $response = $this->send($this->structuredRequest());

        Http::assertSent(fn (Request $request): bool => $request['tools'][0]['name'] === 'ticket_category'
            && $request['tools'][0]['input_schema']['properties']['category']['enum'][0] === 'billing'
            && $request['tool_choice'] === ['type' => 'tool', 'name' => 'ticket_category']);
        $this->assertSame('{"category":"billing"}', $response->content);
    }

    public function test_native_structured_output_can_be_switched_off_for_a_schema_instruction(): void
    {
        $this->replay('short-answer');
        $model = ModelCapabilities::withNativeStructuredOutput(app(AiManager::class)->chat('claude'), false);

        $model->chat($this->structuredRequest());

        Http::assertSent(fn (Request $request): bool => ! isset($request['tools'])
            && ! isset($request['tool_choice'])
            && str_contains($request['system'][1]['text'], 'JSON Schema'));
    }

    public function test_it_reports_what_anthropic_can_and_cannot_do(): void
    {
        $model = app(AiManager::class)->chat('claude');

        $this->assertTrue($model->supports(Capability::Tools));
        $this->assertTrue($model->supports(Capability::CacheBreakpoints));
        $this->assertTrue($model->supports(Capability::Vision));
        $this->assertFalse($model->supports(Capability::Seed));
    }

    public function test_raw_posts_a_provider_shaped_body_and_returns_the_decoded_response(): void
    {
        Http::fake(['*' => Http::response(['id' => 'msgbatch_1', 'processing_status' => 'in_progress'])]);
        $this->replayed = true;

        $body = app(AiManager::class)->chat('claude')->raw('POST', '/v1/messages/batches', ['requests' => []]);

        $this->assertSame('msgbatch_1', $body['id']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://anthropic.test/v1/messages/batches');
    }

    private function replay(string $fixture): void
    {
        FakeHttp::replay('anthropic', $fixture);

        $this->replayed = true;
    }

    private function send(ChatRequest $request): ChatResponse
    {
        if (! $this->replayed) {
            $this->replay('short-answer');
        }

        return app(AiManager::class)->chat('claude')->chat($request);
    }

    private function request(array $messages, array $options = []): ChatRequest
    {
        return ChatRequest::fromOptions($messages, [...$options, 'profile' => null], 'test');
    }

    private function user(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    private function structuredRequest(): ChatRequest
    {
        $prompt = FakeHttp::prompt('structured');

        return ChatRequest::fromOptions($prompt['messages'], [
            'profile' => null,
            'response_format' => $prompt['response_format'],
        ], 'test');
    }
}
