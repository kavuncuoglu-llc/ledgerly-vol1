<?php

namespace Tests\Unit\Ai\Http;

use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Exceptions\UnknownSamplingProfile;
use App\Ai\Http\AiHttpClient;
use App\Ai\Http\CallDetails;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AiHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.base_url', 'http://model.test/v1');
        config()->set('ai.provider', 'ollama');
        config()->set('ai.model', 'default:8b');
        Sleep::fake();
        Event::fake([ModelCalled::class]);
    }

    public function test_chat_posts_the_merged_profile_options_and_messages_to_chat_completions(): void
    {
        Http::fake(['model.test/*' => Http::response($this->completion())]);

        $this->client()->chat(
            messages: [$this->userMessage('What is a late fee?')],
            options: [
                'profile' => 'grounded',
                'max_tokens' => 200,
            ],
            feature: 'support.reply',
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://model.test/v1/chat/completions'
                && $request['model'] === 'default:8b'
                && $request['messages'] === [$this->userMessage('What is a late fee?')]
                && $request['stream'] === false
                && $request['temperature'] === 0.2
                && $request['top_p'] === 0.9
                && $request['max_tokens'] === 200
                && $request->hasHeader('Authorization', 'Bearer ollama');
        });
    }

    public function test_chat_defaults_to_the_assistant_profile(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(messages: [$this->userMessage('Hi')]);

        Http::assertSent(fn (Request $request): bool => $request['temperature'] === 0.7);
    }

    public function test_chat_lets_options_override_the_profile_and_the_model(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(
            messages: [$this->userMessage('Hi')],
            options: [
                'profile' => 'creative',
                'temperature' => 0.1,
                'model' => 'other:1b',
            ],
        );

        Http::assertSent(fn (Request $request): bool => $request['temperature'] === 0.1
            && $request['top_p'] === 0.95
            && $request['model'] === 'other:1b'
            && ! isset($request['profile']));
    }

    public function test_chat_sends_no_profile_values_when_the_profile_is_null(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(
            messages: [$this->userMessage('Hi')],
            options: ['profile' => null],
        );

        Http::assertSent(fn (Request $request): bool => ! isset($request['temperature']) && ! isset($request['top_p']));
    }

    public function test_chat_throws_for_an_unknown_profile(): void
    {
        Http::fake();

        $this->expectException(UnknownSamplingProfile::class);

        $this->client()->chat(
            messages: [$this->userMessage('Hi')],
            options: ['profile' => 'nope'],
        );
    }

    public function test_chat_returns_a_chat_response_and_dispatches_model_called(): void
    {
        Http::fake(['*' => Http::response($this->completion(content: 'Hello there', finishReason: 'length'))]);

        $reply = $this->client()->chat(
            messages: [$this->userMessage('Hi')],
            feature: 'support.reply',
        );

        $this->assertSame('Hello there', $reply->content);
        $this->assertSame('length', $reply->finishReason);
        $this->assertSame('served:8b', $reply->model);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->feature === 'support.reply'
            && $event->provider === 'ollama'
            && $event->model === 'served:8b'
            && $event->usage['prompt_tokens'] === 31
            && $event->usage['completion_tokens'] === 2
            && $event->finishReason === 'length'
            && $event->latencyMs >= 0);
    }

    public function test_chat_passes_the_prompt_version_to_model_called(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(
            messages: [$this->userMessage('Hi')],
            feature: 'ticket.classify',
            promptVersion: '2026-11-01.1',
        );

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->promptVersion === '2026-11-01.1');
    }

    public function test_chat_leaves_the_prompt_version_null_when_none_is_given(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(messages: [$this->userMessage('Hi')]);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->promptVersion === null);
    }

    public function test_chat_does_not_dispatch_model_called_when_the_call_fails(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        try {
            $this->client()->chat(messages: [$this->userMessage('Hi')]);
        } catch (ModelRequestFailed) {
        }

        Event::assertNotDispatched(ModelCalled::class);
    }

    public function test_chat_does_not_retry_a_client_error(): void
    {
        Http::fake(['*' => Http::response('bad key', 401)]);

        try {
            $this->client()->chat(messages: [$this->userMessage('Hi')]);
        } catch (ModelRequestFailed $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_chat_retries_a_transient_server_error_and_then_succeeds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('overloaded', 503)
                ->push('slow down', 429)
                ->push($this->completion(content: 'finally')),
        ]);

        $reply = $this->client()->chat(messages: [$this->userMessage('Hi')]);

        $this->assertSame('finally', $reply->content);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_chat_gives_up_after_three_attempts(): void
    {
        Http::fake(['*' => Http::response('overloaded', 503)]);

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('HTTP 503');

        try {
            $this->client()->chat(messages: [$this->userMessage('Hi')]);
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_chat_retries_a_dead_connection_and_reports_the_server_unreachable(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('refused');
        });

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('Is it running?');

        $this->client()->chat(messages: [$this->userMessage('Hi')]);
    }

    public function test_chat_honors_the_retry_after_header(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('slow down', 429, ['Retry-After' => '7'])
                ->push($this->completion()),
        ]);

        $this->client()->chat(messages: [$this->userMessage('Hi')]);

        Sleep::assertSequence([Sleep::for(7000)->milliseconds()]);
    }

    public function test_chat_throws_when_the_body_has_no_message_content(): void
    {
        Http::fake(['*' => Http::response(['choices' => []])]);

        $this->expectException(ModelRequestFailed::class);

        $this->client()->chat(messages: [$this->userMessage('Hi')]);
    }

    public function test_stream_yields_each_delta_and_returns_the_assembled_response(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk(['role' => 'assistant', 'content' => '']),
            $this->chunk(['content' => 'The']),
            $this->chunk(['content' => ' fee']),
            $this->chunk([], 'stop', ['prompt_tokens' => 18, 'completion_tokens' => 2]),
        ], done: true))]);

        $stream = $this->client()->stream(messages: [$this->userMessage('Hi')], feature: 'cli.stream');

        $deltas = iterator_to_array($stream, false);
        $final = $stream->getReturn();

        $this->assertSame(['The', ' fee'], $deltas);
        $this->assertSame('The fee', $final->content);
        $this->assertSame('stop', $final->finishReason);
        $this->assertSame('chatcmpl-1', $final->id);
        $this->assertSame('served:8b', $final->model);
        $this->assertSame(18, $final->usage->promptTokens);
        $this->assertSame(2, $final->usage->completionTokens);
    }

    public function test_stream_requests_server_sent_events_with_usage(): void
    {
        Http::fake(['*' => Http::response($this->sse([$this->chunk(['content' => 'x'])], done: true))]);

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')]), false);

        Http::assertSent(fn (Request $request): bool => $request['stream'] === true
            && $request['stream_options'] === ['include_usage' => true]);
    }

    public function test_stream_dispatches_model_called_with_the_final_usage(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk(['content' => 'Hi']),
            $this->chunk([], 'stop', ['prompt_tokens' => 18, 'completion_tokens' => 2]),
        ], done: true))]);

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')], feature: 'cli.stream'), false);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->feature === 'cli.stream'
            && $event->model === 'served:8b'
            && $event->usage['prompt_tokens'] === 18
            && $event->finishReason === 'stop');
    }

    public function test_stream_records_the_time_to_the_first_delta(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk(['content' => 'Hi']),
            $this->chunk([], 'stop', ['prompt_tokens' => 18, 'completion_tokens' => 2]),
        ], done: true))]);

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')]), false);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->ttftMs !== null
            && $event->ttftMs <= $event->latencyMs);
    }

    public function test_stream_records_no_first_delta_time_when_nothing_was_streamed(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk([], 'stop', ['prompt_tokens' => 18, 'completion_tokens' => 0]),
        ], done: true))]);

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')]), false);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->ttftMs === null);
    }

    public function test_chat_carries_the_call_details_onto_the_event(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(
            messages: [$this->userMessage('Hi')],
            details: new CallDetails(
                contextBreakdown: ['system' => 10],
                semanticCacheHit: false,
            ),
        );

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->contextBreakdown === ['system' => 10]
            && $event->semanticCacheHit === false
            && $event->ttftMs === null);
    }

    public function test_chat_reports_no_cached_tokens_without_a_conversation_or_a_provider_figure(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);

        $this->client()->chat(messages: [$this->userMessage('Hi')]);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->cachedTokens === null
            && $event->cachedTokensEstimated === false);
    }

    public function test_chat_uses_the_cached_tokens_the_provider_reports(): void
    {
        $body = $this->completion();
        $body['usage']['prompt_tokens_details'] = ['cached_tokens' => 64];
        Http::fake(['*' => Http::response($body)]);

        $this->client()->chat(messages: [$this->userMessage('Hi')], conversationId: 5);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->cachedTokens === 64
            && $event->cachedTokensEstimated === false);
    }

    public function test_chat_estimates_cached_tokens_from_the_prefix_shared_with_the_previous_call_in_the_conversation(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);
        $system = [
            'role' => 'system',
            'content' => str_repeat('A long and stable system message. ', 6),
        ];

        $this->client()->chat(messages: [$system, $this->userMessage('first')], feature: 'support.reply', conversationId: 5);
        $this->client()->chat(messages: [$system, $this->userMessage('second')], feature: 'support.reply', conversationId: 5);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->cachedTokensEstimated === true
            && $event->cachedTokens === 0);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->cachedTokensEstimated === true
            && $event->cachedTokens > 0);
    }

    public function test_stream_passes_the_prompt_version_to_model_called(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk(['content' => 'Hi']),
            $this->chunk([], 'stop', ['prompt_tokens' => 18, 'completion_tokens' => 2]),
        ], done: true))]);

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')], feature: 'support.reply', promptVersion: '2026-11-01.2'), false);

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->promptVersion === '2026-11-01.2');
    }

    public function test_stream_records_zero_usage_when_the_server_omits_it(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk(['content' => 'Hi']),
            $this->chunk([], 'stop'),
        ], done: true))]);

        $stream = $this->client()->stream(messages: [$this->userMessage('Hi')]);
        iterator_to_array($stream, false);

        $this->assertSame(0, $stream->getReturn()->usage->promptTokens);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->usage === [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ]);
    }

    public function test_stream_skips_comments_and_blank_lines(): void
    {
        $body = ": keep-alive\n\n".$this->sse([$this->chunk(['content' => 'ok'])], done: true);
        Http::fake(['*' => Http::response($body)]);

        $deltas = iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')]), false);

        $this->assertSame(['ok'], $deltas);
    }

    public function test_stream_throws_stream_truncated_after_yielding_the_partial_content(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->chunk(['content' => 'The fee is 1.5% of the']),
        ], done: false))]);
        $stream = $this->client()->stream(messages: [$this->userMessage('Hi')], feature: 'cli.stream');
        $received = [];

        try {
            foreach ($stream as $delta) {
                $received[] = $delta;
            }

            $this->fail('Expected StreamTruncated.');
        } catch (StreamTruncated $exception) {
            $this->assertSame('The fee is 1.5% of the', $exception->partialContent);
        }

        $this->assertSame(['The fee is 1.5% of the'], $received);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->finishReason === 'truncated');
    }

    public function test_stream_throws_when_a_chunk_is_not_json(): void
    {
        Http::fake(['*' => Http::response("data: not json\n\ndata: [DONE]\n\n")]);

        $this->expectException(ModelRequestFailed::class);

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')]), false);
    }

    public function test_stream_throws_when_the_server_rejects_the_request(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $this->expectException(ModelRequestFailed::class);
        $this->expectExceptionMessage('HTTP 404');

        iterator_to_array($this->client()->stream(messages: [$this->userMessage('Hi')]), false);
    }

    private function client(): AiHttpClient
    {
        return new AiHttpClient(config('ai'));
    }

    private function userMessage(string $content): array
    {
        return [
            'role' => 'user',
            'content' => $content,
        ];
    }

    private function completion(string $content = 'ok', string $finishReason = 'stop'): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'served:8b',
            'choices' => [
                [
                    'index' => 0,
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
                'total_tokens' => 33,
            ],
        ];
    }

    private function chunk(array $delta, ?string $finishReason = null, ?array $usage = null): array
    {
        $chunk = [
            'id' => 'chatcmpl-1',
            'model' => 'served:8b',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta,
                    'finish_reason' => $finishReason,
                ],
            ],
        ];

        if ($usage !== null) {
            $chunk['usage'] = $usage;
        }

        return $chunk;
    }

    private function sse(array $chunks, bool $done): string
    {
        $lines = array_map(
            fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n",
            $chunks,
        );

        if ($done) {
            $lines[] = "data: [DONE]\n\n";
        }

        return implode('', $lines);
    }
}
