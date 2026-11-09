<?php

namespace Tests\Unit\Ai\Http;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Http\ChatResponse;
use App\Ai\Http\Usage;
use PHPUnit\Framework\TestCase;

class ChatResponseTest extends TestCase
{
    public function test_from_array_reads_the_four_things_every_response_carries(): void
    {
        $body = [
            'id' => 'chatcmpl-9f2a7c',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'The fee is 1.5% per month.',
                        'tool_calls' => null,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 68,
                'completion_tokens' => 19,
                'total_tokens' => 87,
            ],
        ];

        $response = ChatResponse::fromArray($body);

        $this->assertSame('chatcmpl-9f2a7c', $response->id);
        $this->assertSame('llama3.1:8b', $response->model);
        $this->assertSame('The fee is 1.5% per month.', $response->content);
        $this->assertSame([], $response->toolCalls);
        $this->assertSame('stop', $response->finishReason);
        $this->assertSame(68, $response->usage->promptTokens);
        $this->assertSame(19, $response->usage->completionTokens);
        $this->assertSame($body, $response->raw);
    }

    public function test_from_array_accepts_null_content_when_the_model_calls_a_tool(): void
    {
        $response = ChatResponse::fromArray([
            'choices' => [
                [
                    'message' => [
                        'content' => null,
                        'tool_calls' => [['id' => 'call_1']],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ]);

        $this->assertNull($response->content);
        $this->assertSame([['id' => 'call_1']], $response->toolCalls);
    }

    public function test_from_array_throws_when_there_is_no_message(): void
    {
        $this->expectException(ModelRequestFailed::class);

        ChatResponse::fromArray(['choices' => []]);
    }

    public function test_usage_keeps_provider_extras_and_round_trips_to_an_array(): void
    {
        $usage = Usage::fromArray([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'prompt_tokens_details' => ['cached_tokens' => 4],
        ]);

        $this->assertSame(['cached_tokens' => 4], $usage->extras['prompt_tokens_details']);
        $this->assertSame(10, $usage->toArray()['prompt_tokens']);
        $this->assertSame(5, $usage->toArray()['completion_tokens']);
        $this->assertSame(['cached_tokens' => 4], $usage->toArray()['prompt_tokens_details']);
    }
}
