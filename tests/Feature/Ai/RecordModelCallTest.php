<?php

namespace Tests\Feature\Ai;

use App\Ai\Events\ModelCalled;
use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RecordModelCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_records_one_row_per_model_called_event(): void
    {
        event($this->modelCalled());

        $call = ModelCall::query()->sole();

        $this->assertSame('support.reply', $call->feature);
        $this->assertSame('ollama', $call->provider);
        $this->assertSame('llama3.1:8b', $call->model);
        $this->assertSame(1200, $call->prompt_tokens);
        $this->assertSame(150, $call->completion_tokens);
        $this->assertSame('stop', $call->finish_reason);
        $this->assertSame(2840, $call->latency_ms);
        $this->assertNull($call->conversation_id);
    }

    public function test_handle_stores_the_prompt_version_of_the_event(): void
    {
        event($this->modelCalled(promptVersion: '2026-11-01.2'));

        $this->assertSame('2026-11-01.2', ModelCall::query()->sole()->prompt_version);
    }

    public function test_handle_stores_a_null_prompt_version_for_calls_without_a_prompt_class(): void
    {
        event($this->modelCalled());

        $this->assertNull(ModelCall::query()->sole()->prompt_version);
    }

    public function test_handle_costs_a_model_without_a_rate_at_zero(): void
    {
        event($this->modelCalled(provider: 'ollama', model: 'unlisted:1b'));

        $this->assertSame(0, ModelCall::query()->sole()->cost_micros);
    }

    public function test_handle_computes_cost_in_micro_dollars_from_the_rate_table(): void
    {
        config()->set('ai.rates.openai.example-model', [
            'in' => 0.50,
            'out' => 2.00,
        ]);

        event($this->modelCalled(provider: 'openai', model: 'example-model'));

        $this->assertSame(900, ModelCall::query()->sole()->cost_micros);
    }

    public function test_handle_resolves_rates_for_model_names_that_contain_dots(): void
    {
        config()->set('ai.rates.ollama', [
            'llama3.1:8b' => [
                'in' => 1,
                'out' => 2,
            ],
        ]);

        event($this->modelCalled());

        $this->assertSame(1500, ModelCall::query()->sole()->cost_micros);
    }

    public function test_handle_stores_the_conversation_id_when_given(): void
    {
        event($this->modelCalled(conversationId: 42));

        $this->assertSame(42, ModelCall::query()->sole()->conversation_id);
    }

    public function test_handle_logs_a_warning_when_the_completion_was_truncated(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Completion truncated', [
                'feature' => 'support.reply',
                'usage' => [
                    'prompt_tokens' => 1200,
                    'completion_tokens' => 150,
                ],
            ]);

        event($this->modelCalled(finishReason: 'length'));

        $this->assertSame('length', ModelCall::query()->sole()->finish_reason);
    }

    public function test_handle_does_not_log_a_warning_when_the_completion_finished_naturally(): void
    {
        Log::shouldReceive('warning')->never();

        event($this->modelCalled(finishReason: 'stop'));

        $this->assertSame(1, ModelCall::query()->count());
    }

    private function modelCalled(
        string $provider = 'ollama',
        string $model = 'llama3.1:8b',
        string $finishReason = 'stop',
        ?int $conversationId = null,
        ?string $promptVersion = null,
    ): ModelCalled {
        return new ModelCalled(
            feature: 'support.reply',
            provider: $provider,
            model: $model,
            usage: [
                'prompt_tokens' => 1200,
                'completion_tokens' => 150,
            ],
            finishReason: $finishReason,
            latencyMs: 2840,
            conversationId: $conversationId,
            promptVersion: $promptVersion,
        );
    }
}
