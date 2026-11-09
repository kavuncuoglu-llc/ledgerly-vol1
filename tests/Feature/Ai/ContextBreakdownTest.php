<?php

namespace Tests\Feature\Ai;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class ContextBreakdownTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
    }

    public function test_a_support_reply_records_tokens_per_slot(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Plain answer.'))]);

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => 'How do I send an invoice?']);

        $breakdown = ModelCall::query()->sole()->context_breakdown;
        $this->assertSame(['system', 'question', 'tools'], array_keys($breakdown));
        $this->assertGreaterThan(200, $breakdown['system']);
        $this->assertGreaterThan(100, $breakdown['tools']);
    }

    public function test_a_second_turn_adds_history_and_the_summary_slot_when_there_is_one(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Plain answer.'))]);
        $user = $this->customerUser();
        $conversationId = $this->actingAs($user)->postJson('support/ask', ['question' => 'First question'])->json('conversation_id');

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'Second question',
            'conversation_id' => $conversationId,
        ]);

        $second = ModelCall::query()->latest('id')->first()->context_breakdown;
        $this->assertArrayHasKey('history', $second);
        $this->assertArrayNotHasKey('summary', $second);
    }

    public function test_the_round_after_a_tool_call_adds_a_tool_results_slot(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => 'INV-4471', 'month' => null])]))
            ->push($this->answerResponse('It is 18 days overdue.'));

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Look up INV-4471']);

        [$first, $second] = ModelCall::query()->orderBy('id')->get()->all();
        $this->assertArrayNotHasKey('tool_results', $first->context_breakdown);
        $this->assertGreaterThan(0, $second->context_breakdown['tool_results']);
        $this->assertSame($first->context_breakdown['system'], $second->context_breakdown['system']);
    }

    public function test_cached_tokens_are_estimated_from_the_shared_prefix_on_the_second_turn(): void
    {
        Http::fake(['*' => Http::response($this->largePromptResponse())]);
        $user = $this->customerUser();
        $conversationId = $this->actingAs($user)->postJson('support/ask', ['question' => 'First question'])->json('conversation_id');

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'Second question',
            'conversation_id' => $conversationId,
        ]);

        [$first, $second] = ModelCall::query()->orderBy('id')->get()->all();
        $this->assertSame(0, $first->cached_tokens);
        $this->assertTrue($second->cached_tokens_estimated);
        $this->assertGreaterThan(200, $second->cached_tokens);
    }

    public function test_the_estimate_survives_when_the_new_turn_only_appends_to_the_old_prompt(): void
    {
        Http::fake(['*' => Http::response($this->largePromptResponse())]);
        $user = $this->customerUser();
        $conversationId = $this->actingAs($user)->postJson('support/ask', ['question' => 'First question'])->json('conversation_id');

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Second question', 'conversation_id' => $conversationId]);
        $this->actingAs($user)->postJson('support/ask', ['question' => 'Third question', 'conversation_id' => $conversationId]);

        [, $second, $third] = ModelCall::query()->orderBy('id')->get()->all();
        $this->assertGreaterThan($second->cached_tokens, $third->cached_tokens);
    }

    private function largePromptResponse(): array
    {
        $body = $this->answerResponse('Plain answer.');

        $body['usage']['prompt_tokens'] = 1000;

        return $body;
    }
}
