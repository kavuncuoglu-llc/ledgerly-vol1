<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Drivers\Support\GeminiSchema;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\ContentFiltered;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHttp;
use Tests\TestCase;

class GeminiDriverTest extends TestCase
{
    private bool $replayed = false;

    protected function setUp(): void
    {
        parent::setUp();

        WarnOnce::reset();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.gemini-test', [
            'driver' => 'gemini',
            'base_url' => 'http://gemini.test',
            'api_key' => 'g-test',
            'model' => 'gemini-test',
        ]);
    }

    public function test_it_posts_to_the_model_endpoint_with_the_api_key_header(): void
    {
        $this->send($this->request([$this->user('Hi')]));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gemini.test/v1beta/models/gemini-test:generateContent'
            && $request->hasHeader('x-goog-api-key', 'g-test')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_system_messages_become_a_system_instruction_and_assistant_becomes_model(): void
    {
        $this->send($this->request([
            ['role' => 'system', 'content' => 'You are terse.'],
            $this->user('Hi'),
            ['role' => 'assistant', 'content' => 'Hello'],
            $this->user('Again'),
        ]));

        Http::assertSent(fn (Request $request): bool => $request['systemInstruction'] === ['parts' => [['text' => 'You are terse.']]]
            && array_column($request['contents'], 'role') === ['user', 'model', 'user']
            && $request['contents'][1]['parts'] === [['text' => 'Hello']]);
    }

    public function test_options_map_into_generation_config_and_unsupported_ones_are_dropped(): void
    {
        $this->send($this->request([$this->user('Hi')], [
            'max_tokens' => 80,
            'temperature' => 0.3,
            'top_p' => 0.8,
            'stop' => 'END',
            'seed' => 42,
            'min_p' => 0.1,
        ]));

        Http::assertSent(fn (Request $request): bool => $request['generationConfig'] === [
            'maxOutputTokens' => 80,
            'temperature' => 0.3,
            'topP' => 0.8,
            'stopSequences' => ['END'],
        ]);
    }

    public function test_tools_become_function_declarations_with_a_gemini_safe_schema(): void
    {
        $this->send(new ChatRequest(
            messages: [$this->user('Hi')],
            feature: 'test',
            tools: [[
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_invoice',
                    'description' => 'Look up one invoice.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'invoice_number' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['invoice_number'],
                        'additionalProperties' => false,
                    ],
                ],
            ]],
            toolChoice: 'required',
            profile: ChatRequest::NO_PROFILE,
        ));

        Http::assertSent(fn (Request $request): bool => $request['tools'][0]['functionDeclarations'][0] === [
            'name' => 'lookup_invoice',
            'description' => 'Look up one invoice.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['invoice_number' => ['type' => 'string', 'nullable' => true]],
                'required' => ['invoice_number'],
            ],
        ] && $request['toolConfig'] === ['functionCallingConfig' => ['mode' => 'ANY']]);
    }

    public function test_tool_results_are_sent_as_function_responses_named_after_the_call(): void
    {
        $this->send($this->request([
            $this->user('Status of 4471?'),
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => '{"status":"overdue"}'],
        ]));

        Http::assertSent(fn (Request $request): bool => $request['contents'][1] === ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'lookup_invoice', 'args' => ['invoice_number' => '4471']]]]]
            && $request['contents'][2] === ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'lookup_invoice', 'response' => ['status' => 'overdue']]]]]);
    }

    public function test_a_response_format_becomes_a_response_schema(): void
    {
        $this->replay('structured');
        $prompt = FakeHttp::prompt('structured');

        $response = $this->send(ChatRequest::fromOptions($prompt['messages'], [
            'profile' => null,
            'response_format' => $prompt['response_format'],
        ], 'test'));

        Http::assertSent(fn (Request $request): bool => $request['generationConfig']['responseMimeType'] === 'application/json'
            && $request['generationConfig']['responseSchema']['properties']['category']['enum'][0] === 'billing'
            && ! isset($request['generationConfig']['responseSchema']['additionalProperties']));
        $this->assertSame('{"category":"billing"}', $response->content);
    }

    public function test_an_image_data_url_becomes_inline_data(): void
    {
        $this->send($this->request([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Read this'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,QUJD']],
            ],
        ]]));

        Http::assertSent(fn (Request $request): bool => $request['contents'][0]['parts'][1] === ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => 'QUJD']]);
    }

    public function test_streaming_uses_the_server_sent_events_endpoint(): void
    {
        $this->replay('stream-short-answer');

        iterator_to_array(app(AiManager::class)->chat('gemini-test')->stream($this->request([$this->user('Hi')])), false);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gemini.test/v1beta/models/gemini-test:streamGenerateContent?alt=sse');
    }

    public function test_a_blocked_prompt_raises_content_filtered(): void
    {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);
        $this->replayed = true;

        $this->expectException(ContentFiltered::class);

        $this->send($this->request([$this->user('Hi')]));
    }

    public function test_a_function_call_with_no_args_comes_back_as_an_empty_json_object(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'account_status', 'args' => []]]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
        ])]);
        $this->replayed = true;

        $response = $this->send($this->request([$this->user('Status?')]));

        $this->assertSame('{}', $response->toolCalls[0]['function']['arguments']);
        $this->assertSame('call_0', $response->toolCalls[0]['id']);
    }

    public function test_it_reports_what_gemini_can_and_cannot_do(): void
    {
        $model = app(AiManager::class)->chat('gemini-test');

        $this->assertTrue($model->supports(Capability::Tools));
        $this->assertTrue($model->supports(Capability::Vision));
        $this->assertFalse($model->supports(Capability::CacheBreakpoints));
        $this->assertFalse($model->supports(Capability::Seed));
    }

    public function test_the_schema_cleaner_keeps_nested_properties_and_items(): void
    {
        $cleaned = GeminiSchema::clean([
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'lines' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['sku' => ['type' => ['string', 'null']]]],
                ],
            ],
        ]);

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'lines' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string', 'nullable' => true]]],
                ],
            ],
        ], $cleaned);
    }

    private function replay(string $fixture): void
    {
        FakeHttp::replay('gemini', $fixture);

        $this->replayed = true;
    }

    private function send(ChatRequest $request): ChatResponse
    {
        if (! $this->replayed) {
            $this->replay('short-answer');
        }

        return app(AiManager::class)->chat('gemini-test')->chat($request);
    }

    private function request(array $messages, array $options = []): ChatRequest
    {
        return ChatRequest::fromOptions($messages, [...$options, 'profile' => null], 'test');
    }

    private function user(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }
}
