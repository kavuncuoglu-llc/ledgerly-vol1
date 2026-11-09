<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\AccountStatus\AccountStatus;
use App\Ai\Tools\AccountStatus\AccountStatusArgs;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_it_is_a_read_tool_with_one_optional_argument(): void
    {
        $tool = new AccountStatus;

        $this->assertSame(ToolKind::Read, $tool->kind());
        $this->assertSame(['include_open_tickets'], $tool->parameters()['required']);
        $this->assertSame([], $tool->validate([]));
    }

    public function test_validate_accepts_the_words_true_and_false_a_small_model_sends_for_a_boolean(): void
    {
        $tool = new AccountStatus;

        $this->assertSame(['include_open_tickets' => true], $tool->validate(['include_open_tickets' => 'true']));
        $this->assertSame(['include_open_tickets' => false], $tool->validate(['include_open_tickets' => 'false']));
        $this->assertSame(['include_open_tickets' => null], $tool->validate(['include_open_tickets' => null]));
    }

    public function test_validate_still_rejects_a_value_that_is_not_a_boolean(): void
    {
        $this->expectException(ValidationException::class);

        (new AccountStatus)->validate(['include_open_tickets' => 'maybe']);
    }

    public function test_handle_reports_the_plan_overdue_invoices_and_open_tickets(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471', 18);
        $this->invoiceFor($user, 'INV-4438', 41);
        Invoice::factory()->sentDueIn(10)->create(['customer_id' => $user->customer_id]);
        Ticket::factory()->create(['customer_id' => $user->customer_id, 'status' => TicketStatus::Open]);
        Ticket::factory()->resolved()->create(['customer_id' => $user->customer_id]);

        $result = (new AccountStatus)->handle(new AccountStatusArgs, new ToolContext($user));

        $this->assertTrue($result->ok);
        $this->assertSame($user->customer->plan, $result->data['plan']);
        $this->assertSame(2, $result->data['overdue_invoices']);
        $this->assertSame(1, $result->data['outstanding_invoices']);
        $this->assertSame(41, $result->data['oldest_overdue_days']);
        $this->assertSame(1, $result->data['open_tickets']);
    }

    public function test_handle_leaves_out_the_ticket_count_when_asked(): void
    {
        $user = $this->customerUser();

        $result = (new AccountStatus)->handle(new AccountStatusArgs(include_open_tickets: false), new ToolContext($user));

        $this->assertArrayNotHasKey('open_tickets', $result->data);
        $this->assertSame(0, $result->data['oldest_overdue_days']);
    }

    public function test_handle_only_sees_the_session_customer(): void
    {
        $user = $this->customerUser();
        $other = Customer::factory()->create();
        Invoice::factory()->overdueBy(50)->create(['customer_id' => $other->id]);
        Ticket::factory()->create(['customer_id' => $other->id]);

        $result = (new AccountStatus)->handle(new AccountStatusArgs, new ToolContext($user));

        $this->assertSame(0, $result->data['overdue_invoices']);
        $this->assertSame(0, $result->data['open_tickets']);
    }

    public function test_compact_result_summarizes_for_the_agent_state(): void
    {
        $summary = (new AccountStatus)->compactResult(json_encode([
            'ok' => true,
            'plan' => 'business',
            'overdue_invoices' => 3,
            'oldest_overdue_days' => 42,
            'outstanding_invoices' => 1,
            'open_tickets' => 2,
        ]));

        $this->assertSame('plan business; 3 overdue invoices (oldest 42 days); 1 outstanding; 2 open tickets', $summary);
        $this->assertSame('no account status returned', (new AccountStatus)->compactResult('nope'));
    }
}
