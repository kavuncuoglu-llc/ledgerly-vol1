<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Drivers\Errors\BedrockErrors;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Exceptions\StreamTruncated;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\EventStreamFrames;
use Tests\Support\FakeHttp;
use Tests\TestCase;

class BedrockDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WarnOnce::reset();
        Sleep::fake();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.aws', [
            'driver' => 'bedrock',
            'base_url' => 'http://bedrock.test',
            'region' => 'eu-west-1',
            'access_key' => 'AKIDTEST',
            'secret_key' => 'secret-test',
            'session_token' => 'token-test',
            'model' => 'anthropic.claude-test-v1:0',
            'max_tokens' => 321,
        ]);
    }

    public function test_it_posts_a_signed_request_to_the_converse_path_with_the_model_id_encoded(): void
    {
        $this->send($this->request([$this->user('Hi')]));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://bedrock.test/model/anthropic.claude-test-v1%3A0/converse'
                && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIDTEST/')
                && str_contains($request->header('Authorization')[0], '/eu-west-1/bedrock/aws4_request')
                && $request->hasHeader('x-amz-security-token', 'token-test')
                && $request->hasHeader('x-amz-date')
                && $request->method() === 'POST';
        });
    }

    public function test_the_stream_path_is_converse_stream(): void
    {
        FakeHttp::replay('bedrock', 'stream-short-answer');

        iterator_to_array(app(AiManager::class)->chat('aws')->stream($this->request([$this->user('Hi')])), false);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/converse-stream'));
    }

    public function test_system_messages_move_to_the_top_level_system_field(): void
    {
        $this->send($this->request([
            ['role' => 'system', 'content' => 'You are terse.'],
            $this->user('Hi'),
        ]));

        Http::assertSent(fn (Request $request): bool => $request['system'] === [['text' => 'You are terse.']]
            && $request['messages'] === [['role' => 'user', 'content' => [['text' => 'Hi']]]]);
    }

    public function test_options_map_to_inference_config(): void
    {
        $this->send($this->request([$this->user('Hi')], ['temperature' => 0.2, 'top_p' => 0.9, 'stop' => 'END']));

        Http::assertSent(fn (Request $request): bool => $request['inferenceConfig'] === ['temperature' => 0.2, 'topP' => 0.9, 'stopSequences' => ['END'], 'maxTokens' => 321]);
    }

    public function test_max_tokens_defaults_from_config_and_can_be_overridden(): void
    {
        $this->send($this->request([$this->user('Hi')], ['max_tokens' => 50]));

        Http::assertSent(fn (Request $request): bool => $request['inferenceConfig'] === ['maxTokens' => 50]);
    }

    public function test_max_tokens_comes_from_the_provider_config_when_the_request_has_none(): void
    {
        $this->send($this->request([$this->user('Hi')]));

        Http::assertSent(fn (Request $request): bool => $request['inferenceConfig']['maxTokens'] === 321);
    }

    public function test_top_k_goes_to_additional_model_request_fields(): void
    {
        $this->send($this->request([$this->user('Hi')], ['top_k' => 40]));

        Http::assertSent(fn (Request $request): bool => $request['additionalModelRequestFields'] === ['top_k' => 40]);
    }

    public function test_an_unsupported_option_is_dropped_with_one_warning(): void
    {
        Log::spy();

        $this->send($this->request([$this->user('Hi')], ['seed' => 42]));
        $this->send($this->request([$this->user('Hi')], ['seed' => 42]));

        Http::assertSent(fn (Request $request): bool => ! isset($request['seed']) && ! isset($request['inferenceConfig']['seed']));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_tools_become_tool_specs_and_assistant_tool_calls_become_tool_use_blocks(): void
    {
        $this->send(new ChatRequest(
            messages: [
                $this->user('Status of 4471?'),
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']]]],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"status":"overdue"}'],
                ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"status":"paid"}'],
            ],
            feature: 'support.reply',
            tools: [['type' => 'function', 'function' => ['name' => 'lookup_invoice', 'description' => 'Look up one invoice', 'parameters' => ['type' => 'object']]]],
            toolChoice: 'auto',
        ));

        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];

            return $request['toolConfig']['tools'][0]['toolSpec'] === ['name' => 'lookup_invoice', 'description' => 'Look up one invoice', 'inputSchema' => ['json' => ['type' => 'object']]]
                && $request['toolConfig']['toolChoice'] === ['auto' => []]
                && $messages[1]['content'][0]['toolUse'] === ['toolUseId' => 'call_1', 'name' => 'lookup_invoice', 'input' => ['invoice_number' => '4471']]
                && count($messages) === 3
                && $messages[2]['role'] === 'user'
                && count($messages[2]['content']) === 2
                && $messages[2]['content'][0]['toolResult'] === ['toolUseId' => 'call_1', 'content' => [['text' => '{"status":"overdue"}']]];
        });
    }

    public function test_a_response_format_becomes_a_forced_tool_and_comes_back_as_json_content(): void
    {
        FakeHttp::replay('bedrock', 'structured');

        $response = app(AiManager::class)->chat('aws')->chat(ChatRequest::fromOptions(
            messages: [$this->user('I was charged twice')],
            options: ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'ticket_category', 'schema' => ['type' => 'object']]]],
            feature: 'ticket.classify',
        ));

        Http::assertSent(fn (Request $request): bool => $request['toolConfig']['toolChoice'] === ['tool' => ['name' => 'ticket_category']]
            && $request['toolConfig']['tools'][0]['toolSpec']['name'] === 'ticket_category');
        $this->assertSame('{"category":"billing"}', $response->content);
        $this->assertSame([], $response->toolCalls);
    }

    public function test_cache_breakpoints_become_cache_points_after_the_marked_message(): void
    {
        $this->send(new ChatRequest(
            messages: [
                ['role' => 'system', 'content' => 'Stable rules.'],
                $this->user('Question'),
            ],
            feature: 'support.reply',
            cacheBreakpointsAfter: [0],
        ));

        Http::assertSent(fn (Request $request): bool => $request['system'] === [['text' => 'Stable rules.'], ['cachePoint' => ['type' => 'default']]]);
    }

    public function test_an_inline_image_becomes_an_image_block_with_its_format(): void
    {
        $this->send(new ChatRequest(
            messages: [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Read this'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,QUJD']],
                ],
            ]],
            feature: 'invoice.extract',
        ));

        Http::assertSent(fn (Request $request): bool => $request['messages'][0]['content'][1] === ['image' => ['format' => 'jpeg', 'source' => ['bytes' => 'QUJD']]]);
    }

    public function test_the_request_id_header_is_the_response_id_and_the_requested_model_is_the_model(): void
    {
        $response = $this->send($this->request([$this->user('Hi')]));

        $this->assertSame('7f3a1c2e-0000-4000-8000-000000000001', $response->id);
        $this->assertSame('anthropic.claude-test-v1:0', $response->model);
    }

    public function test_cache_read_and_write_tokens_count_toward_the_prompt(): void
    {
        FakeHttp::replay('bedrock', 'cached');

        $response = app(AiManager::class)->chat('aws')->chat($this->request([$this->user('Hi')]));

        $this->assertSame(2060, $response->usage->promptTokens);
        $this->assertSame(2048, $response->usage->cachedTokens);
    }

    public function test_capabilities(): void
    {
        $model = app(AiManager::class)->chat('aws');

        $this->assertTrue($model->supports(Capability::Tools));
        $this->assertTrue($model->supports(Capability::StructuredOutput));
        $this->assertTrue($model->supports(Capability::Vision));
        $this->assertTrue($model->supports(Capability::CacheBreakpoints));
        $this->assertFalse($model->supports(Capability::Seed));
    }

    public function test_a_throttling_error_is_a_rate_limit(): void
    {
        FakeHttp::replay('bedrock', 'rate-limited');

        $this->expectException(RateLimited::class);

        app(AiManager::class)->chat('aws')->chat($this->request([$this->user('Hi')]));
    }

    public function test_a_stream_exception_frame_is_raised_as_a_typed_error(): void
    {
        Http::fake(['*' => Http::response(EventStreamFrames::event('messageStart', ['role' => 'assistant']).EventStreamFrames::exception('validationException', ['message' => 'bad input']), 200)]);

        $this->expectException(InvalidRequest::class);

        iterator_to_array(app(AiManager::class)->chat('aws')->stream($this->request([$this->user('Hi')])), false);
    }

    public function test_stream_exception_types_map_to_the_shared_exceptions(): void
    {
        $errors = new BedrockErrors;

        $this->assertInstanceOf(RateLimited::class, $errors->fromStreamException('throttlingException', '{}'));
        $this->assertInstanceOf(ProviderUnavailable::class, $errors->fromStreamException('modelStreamErrorException', '{}'));
        $this->assertInstanceOf(ProviderUnavailable::class, $errors->fromStreamException('serviceUnavailableException', '{}'));
        $this->assertInstanceOf(InvalidRequest::class, $errors->fromStreamException('validationException', '{}'));
        $this->assertSame(ModelRequestFailed::class, $errors->fromStreamException('somethingNew', '{}')::class);
    }

    public function test_a_stream_that_ends_before_message_stop_is_truncated(): void
    {
        Http::fake(['*' => Http::response(EventStreamFrames::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'part']]), 200)]);

        $this->expectException(StreamTruncated::class);

        iterator_to_array(app(AiManager::class)->chat('aws')->stream($this->request([$this->user('Hi')])), false);
    }

    public function test_a_stream_without_metadata_still_completes_with_the_text(): void
    {
        Http::fake(['*' => Http::response(
            EventStreamFrames::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'done']]).EventStreamFrames::event('messageStop', ['stopReason' => 'end_turn']),
            200,
        )]);

        $stream = app(AiManager::class)->chat('aws')->stream($this->request([$this->user('Hi')]));

        iterator_to_array($stream, false);

        $this->assertSame('done', $stream->getReturn()->content);
        $this->assertSame(FinishReason::Stop, $stream->getReturn()->finishReason);
    }

    public function test_it_is_configured_with_a_region_and_a_model_and_no_api_key(): void
    {
        $ai = app(AiManager::class);

        $this->assertTrue($ai->isConfigured('aws'));

        config()->set('ai.providers.aws.region', null);

        $this->assertFalse($ai->isConfigured('aws'));
    }

    private function send(ChatRequest $request)
    {
        FakeHttp::replay('bedrock', 'short-answer');

        return app(AiManager::class)->chat('aws')->chat($request);
    }

    private function request(array $messages, array $options = []): ChatRequest
    {
        return ChatRequest::fromOptions($messages, [...$options, 'profile' => null], 'support.reply');
    }

    private function user(string $text): array
    {
        return [
            'role' => 'user',
            'content' => $text,
        ];
    }
}
