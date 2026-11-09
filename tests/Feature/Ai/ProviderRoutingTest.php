<?php

namespace Tests\Feature\Ai;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Facades\Ai;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class ProviderRoutingTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_two_providers_run_at_once_and_model_calls_names_both(): void
    {
        $this->configureFrontier();
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471');
        Http::fake([
            'localhost:11434/*' => Http::sequence()
                ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
                ->push($this->toolCallResponse([$this->toolCall('call_2', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])])),
            'anthropic.test/*' => Http::response($this->anthropicAnswer('INV-4471 is overdue.')),
        ]);

        $result = app(ToolRunner::class)
            ->escalatingAfter(2)
            ->run([['role' => 'user', 'content' => 'Why is 4471 unpaid?']], new ToolContext($user), feature: 'support.reply');

        $this->assertSame('INV-4471 is overdue.', $result->response->content);
        $this->assertSame(3, $result->rounds);
        $this->assertSame(['local', 'local', 'frontier'], ModelCall::query()->orderBy('id')->pluck('provider')->all());
        $this->assertSame(['llama3.1:8b', 'llama3.1:8b', 'claude-test'], ModelCall::query()->orderBy('id')->pluck('model')->all());
    }

    public function test_the_escalated_round_replays_the_earlier_rounds_in_the_new_providers_own_format(): void
    {
        $this->configureFrontier();
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471');
        Http::fake([
            'localhost:11434/*' => Http::sequence()
                ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
                ->push($this->toolCallResponse([$this->toolCall('call_2', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])])),
            'anthropic.test/*' => Http::response($this->anthropicAnswer('Done.')),
        ]);

        app(ToolRunner::class)
            ->escalatingAfter(2)
            ->run([['role' => 'user', 'content' => 'Why is 4471 unpaid?']], new ToolContext($user), feature: 'support.reply');

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), 'http://anthropic.test')) {
                return false;
            }

            $roles = array_column($request['messages'], 'role');
            $blocks = array_merge(...array_column($request['messages'], 'content'));

            return $roles === ['user', 'assistant', 'user', 'assistant', 'user']
                && array_column($blocks, 'type') === ['text', 'tool_use', 'tool_result', 'tool_use', 'tool_result']
                && in_array('lookup_invoice', array_column($request['tools'], 'name'), true);
        });
    }

    public function test_without_a_configured_frontier_provider_every_round_stays_local(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471');
        Http::fake([
            '*' => Http::sequence()
                ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
                ->push($this->toolCallResponse([$this->toolCall('call_2', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
                ->push($this->answerResponse('Still local.')),
        ]);

        $result = app(ToolRunner::class)
            ->escalatingAfter(2)
            ->run([['role' => 'user', 'content' => 'Why?']], new ToolContext($user), feature: 'support.reply');

        $this->assertSame('Still local.', $result->response->content);
        $this->assertSame(['local', 'local', 'local'], ModelCall::query()->orderBy('id')->pluck('provider')->all());
    }

    public function test_escalation_is_asked_for_by_provider_name_in_the_fake_too(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471');
        Ai::fake()
            ->for('support.reply')->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null], times: 2)
            ->for('support.reply')->respondWith('Resolved.');

        app(ToolRunner::class)
            ->escalatingAfter(2)
            ->run([['role' => 'user', 'content' => 'Why?']], new ToolContext($user), feature: 'support.reply');

        Ai::assertSent(fn (ChatRequest $request, string $provider): bool => $provider === 'frontier');
        $providers = array_column(Ai::getFacadeRoot()->ledger()->sent(), 'provider');
        $this->assertSame(['local', 'local', 'frontier'], $providers);
    }

    public function test_cache_breakpoints_reach_a_provider_that_supports_them_and_are_ignored_by_one_that_does_not(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('ok'))]);
        $messages = [
            ['role' => 'system', 'content' => 'Stable'],
            ['role' => 'user', 'content' => 'Q'],
        ];

        app(AiManager::class)->for('support.reply')->chat(ChatRequest::fromOptions($messages, ['cache_breakpoints_after' => [0]], 'support.reply'));
        config()->set('ai.providers.local.cache_breakpoints', true);
        app(AiManager::class)->forget();
        app(AiManager::class)->for('support.reply')->chat(ChatRequest::fromOptions($messages, ['cache_breakpoints_after' => [0]], 'support.reply'));

        Http::assertSent(fn (Request $request): bool => ! isset($request['messages'][0]['cache_control']));
        Http::assertSent(fn (Request $request): bool => ($request['messages'][0]['cache_control'] ?? null) === ['type' => 'ephemeral'] && ! isset($request['messages'][1]['cache_control']));
    }

    private function configureFrontier(): void
    {
        config()->set('ai.providers.frontier', [
            'driver' => 'anthropic',
            'base_url' => 'http://anthropic.test',
            'api_key' => 'sk-test',
            'model' => 'claude-test',
        ]);
    }

    private function anthropicAnswer(string $text): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 9],
        ];
    }
}
