<?php

namespace Tests\Feature\Ai;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use Generator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeHttp;
use Tests\TestCase;
use Throwable;

class DriverConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.conformance-openai-compatible', [
            'driver' => 'openai-compatible',
            'base_url' => 'http://openai.test/v1',
            'api_key' => 'test-key',
            'model' => 'conformance-model',
        ]);
        config()->set('ai.providers.conformance-ollama', [
            'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434/v1',
            'api_key' => 'ollama',
            'model' => 'conformance-model',
        ]);
        config()->set('ai.providers.conformance-llama-server', [
            'driver' => 'llama-server',
            'base_url' => 'http://llama.test:8080/v1',
            'api_key' => 'llama-key',
            'model' => 'conformance-model',
        ]);
        config()->set('ai.providers.conformance-anthropic', [
            'driver' => 'anthropic',
            'base_url' => 'http://anthropic.test',
            'api_key' => 'test-key',
            'model' => 'conformance-model',
            'max_tokens' => 100,
        ]);
        config()->set('ai.providers.conformance-gemini', [
            'driver' => 'gemini',
            'base_url' => 'http://gemini.test',
            'api_key' => 'test-key',
            'model' => 'conformance-model',
        ]);
        config()->set('ai.providers.conformance-bedrock', [
            'driver' => 'bedrock',
            'base_url' => 'http://bedrock.test',
            'region' => 'us-east-1',
            'access_key' => 'AKIDTEST',
            'secret_key' => 'secret-test',
            'model' => 'conformance-model',
            'max_tokens' => 100,
        ]);
    }

    #[DataProvider('drivers')]
    public function test_a_short_answer_normalizes_to_content_stop_and_usage(string $driver): void
    {
        FakeHttp::replay($driver, 'short-answer');

        $response = $this->chat($driver, 'short-answer');

        $this->assertSame('duplicate', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame([], $response->toolCalls);
        $this->assertSame(35, $response->usage->promptTokens);
        $this->assertSame(2, $response->usage->completionTokens);
        $this->assertNotSame('', $response->model);
    }

    #[DataProvider('drivers')]
    public function test_a_length_stop_normalizes_to_length(string $driver): void
    {
        FakeHttp::replay($driver, 'truncated');

        $response = $this->chat($driver, 'truncated');

        $this->assertSame(FinishReason::Length, $response->finishReason);
        $this->assertSame('Late fees on a monthly', $response->content);
        $this->assertSame(5, $response->usage->completionTokens);
    }

    #[DataProvider('drivers')]
    public function test_a_tool_call_normalizes_to_the_shared_tool_call_shape(string $driver): void
    {
        FakeHttp::replay($driver, 'tool-call');

        $response = $this->chat($driver, 'tool-call');

        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertCount(1, $response->toolCalls);
        $call = $response->toolCalls[0];
        $this->assertNotSame('', $call['id']);
        $this->assertSame('function', $call['type']);
        $this->assertSame('lookup_invoice', $call['function']['name']);
        $this->assertSame(['invoice_number' => '4471'], json_decode($call['function']['arguments'], true));
    }

    #[DataProvider('drivers')]
    public function test_structured_output_comes_back_as_json_content(string $driver): void
    {
        FakeHttp::replay($driver, 'structured');

        $response = $this->chat($driver, 'structured');

        $this->assertSame(['category' => 'billing'], json_decode((string) $response->content, true));
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame([], $response->toolCalls);
    }

    #[DataProvider('drivers')]
    public function test_cached_tokens_land_in_one_usage_field(string $driver): void
    {
        FakeHttp::replay($driver, 'cached');

        $response = $this->chat($driver, 'short-answer');

        $this->assertSame(2048, $response->usage->cachedTokens);
        $this->assertSame(2060, $response->usage->promptTokens);
    }

    #[DataProvider('driversWithContentFilter')]
    public function test_a_content_filter_stop_normalizes_to_content_filter(string $driver): void
    {
        FakeHttp::replay($driver, 'filtered');

        $response = $this->chat($driver, 'short-answer');

        $this->assertSame(FinishReason::ContentFilter, $response->finishReason);
    }

    #[DataProvider('drivers')]
    public function test_an_unrecognized_finish_reason_becomes_truncated_not_stop(string $driver): void
    {
        FakeHttp::replay($driver, 'unknown-finish');

        $response = $this->chat($driver, 'short-answer');

        $this->assertSame(FinishReason::Truncated, $response->finishReason);
    }

    #[DataProvider('errors')]
    public function test_provider_errors_normalize_to_the_same_exception_types(string $driver, string $fixture, string $exception, int $attempts): void
    {
        FakeHttp::replay($driver, $fixture);

        try {
            $this->chat($driver, 'short-answer');

            $this->fail('Expected '.$exception);
        } catch (Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);
        }

        Http::assertSentCount($attempts);
    }

    #[DataProvider('drivers')]
    public function test_a_stream_yields_deltas_then_completed_and_returns_the_response(string $driver): void
    {
        FakeHttp::replay($driver, 'stream-short-answer');

        $stream = $this->streamOf($driver, 'stream-short-answer');

        $events = iterator_to_array($stream, false);
        $response = $stream->getReturn();

        $text = implode('', array_map(fn (Delta $delta): string => $delta->text, array_filter($events, fn (mixed $event): bool => $event instanceof Delta)));
        $this->assertSame('duplicate', $text);
        $this->assertInstanceOf(Completed::class, end($events));
        $this->assertSame('duplicate', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(35, $response->usage->promptTokens);
        $this->assertSame(2, $response->usage->completionTokens);
    }

    #[DataProvider('drivers')]
    public function test_a_streamed_tool_call_starts_before_its_arguments_arrive(string $driver): void
    {
        FakeHttp::replay($driver, 'stream-tool-call');

        $stream = $this->streamOf($driver, 'stream-tool-call');

        $events = iterator_to_array($stream, false);
        $response = $stream->getReturn();

        $kinds = array_map(fn (object $event): string => $event::class, array_filter($events, fn (mixed $event): bool => $event instanceof ToolCallStarted || $event instanceof ToolCallArguments));
        $this->assertSame(ToolCallStarted::class, array_values($kinds)[0]);
        $this->assertEquals(new ToolCallStarted(0, 'lookup_invoice'), array_values(array_filter($events, fn (mixed $event): bool => $event instanceof ToolCallStarted))[0]);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertSame('lookup_invoice', $response->toolCalls[0]['function']['name']);
        $this->assertSame(['invoice_number' => '4471'], json_decode($response->toolCalls[0]['function']['arguments'], true));
    }

    #[DataProvider('drivers')]
    public function test_every_call_logs_the_provider_name_not_the_vendor(string $driver): void
    {
        FakeHttp::replay($driver, 'short-answer');

        $this->chat($driver, 'short-answer');

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === "conformance-{$driver}");
    }

    public static function drivers(): array
    {
        return [
            'openai-compatible' => ['openai-compatible'],
            'ollama' => ['ollama'],
            'llama-server' => ['llama-server'],
            'anthropic' => ['anthropic'],
            'gemini' => ['gemini'],
            'bedrock' => ['bedrock'],
        ];
    }

    public static function driversWithContentFilter(): array
    {
        return array_diff_key(self::drivers(), ['ollama' => true, 'llama-server' => true]);
    }

    public static function errors(): array
    {
        $cases = [];

        foreach (['openai-compatible', 'anthropic', 'gemini', 'bedrock'] as $driver) {
            $cases["{$driver} rate limit"] = [$driver, 'rate-limited', RateLimited::class, 3];
            $cases["{$driver} overloaded"] = [$driver, 'overloaded', ProviderUnavailable::class, 3];
            $cases["{$driver} context overflow"] = [$driver, 'context-overflow', ContextTooLong::class, 1];
        }

        $cases['llama-server loading'] = ['llama-server', 'overloaded', ProviderUnavailable::class, 3];
        $cases['llama-server context overflow'] = ['llama-server', 'context-overflow', ContextTooLong::class, 1];

        $cases['ollama rate limit'] = ['ollama', 'rate-limited', RateLimited::class, 3];
        $cases['ollama busy'] = ['ollama', 'overloaded', ProviderUnavailable::class, 3];

        return $cases;
    }

    private function chat(string $driver, string $prompt): ChatResponse
    {
        return app(AiManager::class)->chat("conformance-{$driver}")->chat($this->requestFor($prompt));
    }

    private function streamOf(string $driver, string $prompt): Generator
    {
        return app(AiManager::class)->chat("conformance-{$driver}")->stream($this->requestFor($prompt));
    }

    private function requestFor(string $prompt): ChatRequest
    {
        $fixture = FakeHttp::prompt($prompt);

        return ChatRequest::fromOptions(
            messages: $fixture['messages'],
            options: [
                ...($fixture['options'] ?? []),
                'profile' => null,
                'tools' => $fixture['tools'] ?? [],
                'response_format' => $fixture['response_format'] ?? null,
            ],
            feature: 'conformance',
        );
    }
}
