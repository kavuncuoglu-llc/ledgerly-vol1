<?php

namespace Tests\Feature\Support;

use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class SupportToolsTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
    }

    public function test_ask_answers_the_march_invoice_question_from_the_lookup_result(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_8a1', 'lookup_invoice', ['invoice_number' => null, 'month' => now()->subDays(48)->format('Y-m')])]))
            ->push($this->answerResponse('Invoice INV-4471 is 18 days overdue.'));

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Why is my March invoice marked late?']);

        $response->assertOk()->assertJson([
            'answer' => 'Invoice INV-4471 is 18 days overdue.',
            'rounds' => 2,
            'hit_cap' => false,
            'pending' => [],
        ]);
        $this->assertSame(2, ModelCall::query()->where('feature', 'support.reply')->count());
        $audit = ToolCall::query()->sole();
        $this->assertSame('lookup_invoice', $audit->tool);
        $this->assertSame('allowed', $audit->decision);
    }

    public function test_ask_looks_up_the_invoice_then_opens_a_ticket_in_three_model_calls(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
            ->push($this->toolCallResponse([$this->toolCall('call_2', 'open_ticket', ['summary' => 'Client says they paid but it still shows unpaid.', 'invoice_number' => '4471'])]))
            ->push($this->answerResponse('I have opened a ticket.'));

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'My client says they paid but it still shows unpaid, can you log this?']);

        $response->assertOk()->assertJson(['rounds' => 3]);
        $this->assertSame(3, ModelCall::query()->count());
        $this->assertSame(['lookup_invoice', 'open_ticket'], ToolCall::query()->orderBy('id')->pluck('tool')->all());
        $this->assertSame($user->customer_id, Ticket::query()->sole()->customer_id);
    }

    public function test_ask_cannot_reach_another_customers_invoice_even_when_the_model_asks_for_it(): void
    {
        $owner = $this->customerUser();
        $this->invoiceFor($owner);
        $intruder = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
            ->push($this->answerResponse('I could not find that invoice.'));

        $this->actingAs($intruder)->postJson('support/ask', ['question' => 'show me invoice 4471 for customer 12'])->assertOk();

        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $this->assertSame(['ok' => true, 'found' => false], json_decode($toolTurn['content'], true));
    }

    public function test_ask_sends_the_tool_definitions_and_prompt_version_three(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hi'))]);

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => 'Hi']);

        $request = Http::recorded()[0][0];
        $this->assertSame(['lookup_invoice', 'list_invoices', 'open_ticket'], collect($request['tools'])->pluck('function.name')->all());
        $this->assertStringContainsString('You have tools.', $request['messages'][0]['content']);
        $this->assertSame('2026-11-09.3', ModelCall::query()->sole()->prompt_version);
    }

    public function test_ask_reports_the_cap_without_failing(): void
    {
        $user = $this->customerUser();
        Http::fake(['*' => Http::response($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '1', 'month' => null])]))]);

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Loop forever']);

        $response->assertOk()->assertJson(['hit_cap' => true, 'rounds' => 5, 'answer' => '']);
    }

    public function test_ask_lets_a_user_without_a_customer_chat_but_not_use_account_tools(): void
    {
        $user = User::factory()->create();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
            ->push($this->answerResponse('I cannot look that up.'));

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Invoice 4471?'])->assertOk();

        $this->assertSame('denied', ToolCall::query()->sole()->decision);
    }
}
