<?php

namespace Tests\Unit\Ai\Sampling;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Sampling\ChatSampler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatSamplerTest extends TestCase
{
    public function test_sample_posts_the_prompt_and_parameters_to_chat_completions(): void
    {
        config()->set('ai.base_url', 'http://model.test/v1');
        Http::fake([
            'model.test/*' => Http::response($this->completion()),
        ]);

        (new ChatSampler)->sample(
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

    public function test_sample_defaults_to_the_configured_model(): void
    {
        config()->set('ai.model', 'configured:8b');
        Http::fake([
            '*' => Http::response($this->completion()),
        ]);

        (new ChatSampler)->sample(prompt: 'Hello');

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'configured:8b');
    }

    public function test_sample_returns_content_finish_reason_and_usage(): void
    {
        Http::fake([
            '*' => Http::response($this->completion(content: 'billing', finishReason: 'length')),
        ]);

        $result = (new ChatSampler)->sample(prompt: 'Hello');

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

        (new ChatSampler)->sample(prompt: 'Hello');
    }

    public function test_sample_throws_when_the_server_is_unreachable(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('refused');
        });

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('Is it running?');

        (new ChatSampler)->sample(prompt: 'Hello');
    }

    public function test_sample_throws_when_the_body_has_no_message_content(): void
    {
        Http::fake([
            '*' => Http::response(['choices' => []]),
        ]);

        $this->expectException(ModelRequestFailed::class);

        (new ChatSampler)->sample(prompt: 'Hello');
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
