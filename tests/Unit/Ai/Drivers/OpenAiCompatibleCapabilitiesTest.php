<?php

namespace Tests\Unit\Ai\Drivers;

use App\Ai\AiManager;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Events\ModelCalled;
use App\Ai\ModelCapabilities;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class OpenAiCompatibleCapabilitiesTest extends TestCase
{
    use FakesChatCompletions;

    protected function setUp(): void
    {
        parent::setUp();

        WarnOnce::reset();
        Event::fake([ModelCalled::class]);
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);
    }

    public function test_the_local_provider_reports_seed_and_structured_output_but_not_vision(): void
    {
        $this->fakeOk();

        $model = app(AiManager::class)->chat('local');

        $this->assertTrue($model->supports(Capability::Tools));
        $this->assertTrue($model->supports(Capability::StructuredOutput));
        $this->assertTrue($model->supports(Capability::Seed));
        $this->assertFalse($model->supports(Capability::Vision));
        $this->assertFalse($model->supports(Capability::CacheBreakpoints));
    }

    public function test_the_vision_provider_reports_vision(): void
    {
        $this->fakeOk();

        $this->assertTrue(app(AiManager::class)->chat('vision')->supports(Capability::Vision));
    }

    public function test_a_provider_can_declare_its_capabilities_outright(): void
    {
        $this->fakeOk();

        config()->set('ai.providers.local.capabilities', ['tools']);

        $model = app(AiManager::class)->chat('local');

        $this->assertTrue($model->supports(Capability::Tools));
        $this->assertFalse($model->supports(Capability::StructuredOutput));
        $this->assertFalse($model->supports(Capability::Seed));
    }

    public function test_hosted_openai_drops_options_it_rejects_and_keeps_the_seed(): void
    {
        $this->fakeOk();

        config()->set('ai.providers.local.base_url', 'https://api.openai.com/v1');

        app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions(
            [['role' => 'user', 'content' => 'Hi']],
            ['profile' => null, 'seed' => 7, 'min_p' => 0.05, 'top_k' => 40],
            'test',
        ));

        Http::assertSent(fn (Request $request): bool => $request['seed'] === 7 && ! isset($request['min_p']) && ! isset($request['top_k']));
    }

    public function test_a_local_server_gets_min_p_passed_through(): void
    {
        $this->fakeOk();

        app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions(
            [['role' => 'user', 'content' => 'Hi']],
            ['profile' => null, 'min_p' => 0.05],
            'test',
        ));

        Http::assertSent(fn (Request $request): bool => $request['min_p'] === 0.05);
    }

    public function test_the_context_window_override_adds_num_ctx_to_one_model_only(): void
    {
        $this->fakeOk();

        $base = app(AiManager::class)->chat('local');
        $wide = ModelCapabilities::withContextWindow($base, 16384);
        $request = ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'test');

        $wide->chat($request);
        $base->chat($request);

        Http::assertSent(fn (Request $sent): bool => ($sent['num_ctx'] ?? null) === 16384);
        Http::assertSent(fn (Request $sent): bool => ! isset($sent['num_ctx']));
    }

    public function test_raw_sends_a_provider_shaped_get_and_returns_the_decoded_body(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'llama3.1:8b']]])]);

        $body = app(AiManager::class)->chat('local')->raw('GET', '/models');

        $this->assertSame('llama3.1:8b', $body['data'][0]['id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'http://localhost:11434/v1/models');
    }

    public function test_the_structured_client_sends_no_response_format_to_a_provider_that_cannot_enforce_it(): void
    {
        config()->set('ai.providers.local.capabilities', ['tools']);
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('bug')))]);

        app(StructuredClient::class)->extract(TicketData::class, [['role' => 'user', 'content' => 'Extract this']], feature: 'ticket.extract');

        Http::assertSent(fn (Request $request): bool => ! isset($request['response_format']));
    }

    public function test_the_structured_client_sends_the_schema_to_a_provider_that_can(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('bug')))]);

        app(StructuredClient::class)->extract(TicketData::class, [['role' => 'user', 'content' => 'Extract this']], feature: 'ticket.extract');

        Http::assertSent(fn (Request $request): bool => $request['response_format']['type'] === 'json_schema');
    }

    public function test_debug_mode_logs_the_wire_request_and_response_for_one_conversation_only(): void
    {
        $this->fakeOk();

        $logged = storage_path('logs/ai-debug-77.log');
        $other = storage_path('logs/ai-debug-78.log');
        File::delete([$logged, $other]);
        config()->set('ai.debug.conversations', [77]);

        try {
            app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'support.reply', conversationId: 77));
            app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'support.reply', conversationId: 78));

            $entry = json_decode((string) file_get_contents($logged), true);

            $this->assertSame('local', $entry['provider']);
            $this->assertSame('/chat/completions', $entry['path']);
            $this->assertSame('Hi', $entry['request']['messages'][0]['content']);
            $this->assertSame('ok', $entry['response']['choices'][0]['message']['content']);
            $this->assertFileDoesNotExist($other);
            $this->assertStringNotContainsString('Bearer', (string) file_get_contents($logged));
        } finally {
            File::delete([$logged, $other]);
        }
    }

    public function test_debug_mode_can_be_switched_on_for_one_feature(): void
    {
        $this->fakeOk();

        $logged = storage_path('logs/ai-debug-ticket.classify.log');
        File::delete($logged);
        config()->set('ai.debug.features', ['ticket.classify']);

        try {
            app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['profile' => null], 'ticket.classify'));

            $this->assertFileExists($logged);
        } finally {
            File::delete($logged);
        }
    }

    public function test_the_raw_field_is_always_populated(): void
    {
        $this->fakeOk();

        $response = app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], [], 'test'));

        $this->assertSame('ok', $response->raw['choices'][0]['message']['content']);
    }
}
