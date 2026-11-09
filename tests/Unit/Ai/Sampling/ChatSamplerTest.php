<?php

namespace Tests\Unit\Ai\Sampling;

use App\Ai\AiManager;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Sampling\ChatSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ChatSamplerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_sample_posts_the_prompt_and_parameters_to_chat_completions(): void
    {
        config()->set('ai.providers.local.base_url', 'http://model.test/v1');
        Http::fake([
            'model.test/*' => Http::response($this->completion()),
        ]);

        $this->sampler()->sample(
            prompt: 'Hello',
            parameters: [
                'temperature' => 0,
                'stop' => ["\n"],
            ],
            model: 'tiny:1b',
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://model.test/v1/chat/completions'
                && $request['model'] === 'tiny:1b'
                && $request['temperature'] === 0
                && $request['stop'] === ["\n"]
                && $request['stream'] === false
                && $request['messages'] === [['role' => 'user', 'content' => 'Hello']]
                && $request->hasHeader('Authorization', 'Bearer ollama');
        });
    }

    public function test_chat_sends_the_given_messages_untouched(): void
    {
        Http::fake([
            '*' => Http::response($this->completion()),
        ]);
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Read this',
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:image/jpeg;base64,AAAA',
                        ],
                    ],
                ],
            ],
        ];

        $this->sampler()->chat(messages: $messages, model: 'vision:1b');

        Http::assertSent(fn (Request $request): bool => $request['messages'] === $messages && $request['model'] === 'vision:1b');
    }

    public function test_sample_defaults_to_the_configured_model(): void
    {
        config()->set('ai.providers.local.model', 'configured:8b');
        Http::fake([
            '*' => Http::response($this->completion()),
        ]);

        $this->sampler()->sample(prompt: 'Hello');

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'configured:8b');
    }

    public function test_sample_returns_content_finish_reason_and_usage(): void
    {
        Http::fake([
            '*' => Http::response($this->completion(content: 'billing', finishReason: 'length')),
        ]);

        $result = $this->sampler()->sample(prompt: 'Hello');

        $this->assertSame('billing', $result->content);
        $this->assertSame('length', $result->finishReason);
        $this->assertSame(31, $result->promptTokens);
        $this->assertSame(2, $result->completionTokens);
    }

    public function test_sample_throws_when_the_server_rejects_the_request(): void
    {
        Http::fake([
            '*' => Http::response('model not found', 404),
        ]);

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('HTTP 404');

        $this->sampler()->sample(prompt: 'Hello');
    }

    public function test_sample_throws_when_the_server_is_unreachable(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('refused');
        });

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('Is it running?');

        $this->sampler()->sample(prompt: 'Hello');
    }

    public function test_sample_throws_when_the_body_has_no_message_content(): void
    {
        Http::fake([
            '*' => Http::response(['choices' => []]),
        ]);

        $this->expectException(ModelRequestFailed::class);

        $this->sampler()->sample(prompt: 'Hello');
    }

    private function sampler(): ChatSampler
    {
        return new ChatSampler(app(AiManager::class));
    }

    private function completion(string $content = 'ok', string $finishReason = 'stop'): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => 31,
                'completion_tokens' => 2,
            ],
        ];
    }
}
