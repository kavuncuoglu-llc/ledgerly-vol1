<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Events\ModelCalled;
use App\Ai\ModelCapabilities;
use App\Ai\Native\OllamaEndpoint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class OpenAiCompatibleOllamaDropDownTest extends TestCase
{
    use FakesChatCompletions;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ModelCalled::class]);
    }

    private function fakeBothEndpoints(): void
    {
        Http::fake([
            '*/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'native'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 12,
                'eval_count' => 1,
            ]),
            '*' => Http::response($this->completion('compatible')),
        ]);
    }

    public function test_by_default_the_local_provider_stays_on_the_compatible_endpoint(): void
    {
        $this->fakeBothEndpoints();

        $response = $this->chatLocal();

        $this->assertSame('compatible', $response->content);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/chat/completions'));
    }

    public function test_the_native_switch_moves_every_ollama_chat_to_api_chat(): void
    {
        $this->fakeBothEndpoints();

        config()->set('ai.ollama.native', true);

        $response = $this->chatLocal();

        $this->assertSame('native', $response->content);
        $this->assertSame(12, $response->usage->promptTokens);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:11434/api/chat' && $request['keep_alive'] === '30m');
    }

    public function test_a_configured_num_ctx_moves_chat_to_the_native_endpoint_and_is_sent(): void
    {
        $this->fakeBothEndpoints();

        config()->set('ai.ollama.num_ctx', 8192);

        $this->chatLocal();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat') && $request['options']['num_ctx'] === 8192);
    }

    public function test_the_context_window_override_drops_to_native_for_ollama_only(): void
    {
        $this->fakeBothEndpoints();

        $base = app(AiManager::class)->chat('local');
        $wide = ModelCapabilities::withContextWindow($base, 16384);
        $request = ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'test');

        $wide->chat($request);
        $base->chat($request);

        Http::assertSent(fn (Request $sent): bool => str_ends_with($sent->url(), '/api/chat') && $sent['options']['num_ctx'] === 16384);
        Http::assertSent(fn (Request $sent): bool => str_ends_with($sent->url(), '/v1/chat/completions') && ! isset($sent['num_ctx']));
    }

    public function test_a_provider_that_is_not_ollama_never_drops_to_the_native_endpoint(): void
    {
        $this->fakeBothEndpoints();

        config()->set('ai.ollama.native', true);
        config()->set('ai.providers.local.base_url', 'http://vllm.test:8000/v1');

        $wide = ModelCapabilities::withContextWindow(app(AiManager::class)->chat('local'), 16384);
        $wide->chat(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'test'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://vllm.test:8000/v1/chat/completions' && $request['num_ctx'] === 16384);
    }

    public function test_a_stream_through_the_drop_down_returns_the_native_response(): void
    {
        config()->set('ai.ollama.native', true);
        Http::fake(['*' => Http::response(implode("\n", [
            (string) json_encode(['message' => ['role' => 'assistant', 'content' => 'Hi'], 'done' => false]),
            (string) json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 9, 'eval_count' => 1]),
        ])."\n")]);

        $stream = app(AiManager::class)->chat('local')->stream(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'test'));
        iterator_to_array($stream, false);

        $this->assertSame('Hi', $stream->getReturn()->content);
        $this->assertSame(9, $stream->getReturn()->usage->promptTokens);
    }

    #[DataProvider('endpoints')]
    public function test_an_endpoint_is_recognised_as_ollama_by_flag_driver_or_port(array $config, bool $expected): void
    {
        $this->assertSame($expected, OllamaEndpoint::isOllama($config));
    }

    public static function endpoints(): array
    {
        return [
            'default port' => [['base_url' => 'http://localhost:11434/v1'], true],
            'other port' => [['base_url' => 'http://vllm.test:8000/v1'], false],
            'hosted' => [['base_url' => 'https://api.openai.com/v1'], false],
            'forced on' => [['base_url' => 'http://gateway.test/v1', 'ollama' => true], true],
            'forced off' => [['base_url' => 'http://localhost:11434/v1', 'ollama' => false], false],
            'driver' => [['driver' => 'ollama', 'base_url' => 'http://gateway.test/v1'], true],
        ];
    }

    public function test_the_native_base_url_loses_only_a_trailing_v1(): void
    {
        $this->assertSame('http://ollama:11434', OllamaEndpoint::nativeBaseUrl('http://ollama:11434/v1'));
        $this->assertSame('http://ollama:11434', OllamaEndpoint::nativeBaseUrl('http://ollama:11434/v1/'));
        $this->assertSame('http://proxy.test/v1/ollama', OllamaEndpoint::nativeBaseUrl('http://proxy.test/v1/ollama'));
    }

    private function chatLocal(): ChatResponse
    {
        return app(AiManager::class)->chat('local')->chat(
            ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'test'),
        );
    }
}
