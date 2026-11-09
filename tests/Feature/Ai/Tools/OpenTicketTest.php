<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\OpenTicket\OpenTicketArgs;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class OpenTicketTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_it_is_a_low_risk_write(): void
    {
        $this->assertSame(ToolKind::WriteLowRisk, (new OpenTicket)->kind());
    }

    public function test_handle_opens_a_ticket_for_the_session_customer_linked_to_the_invoice(): void
    {
        $user = $this->customerUser();
        $invoice = $this->invoiceFor($user);
        Ticket::factory()->create(['number' => 'T-10421']);

        $result = (new OpenTicket)->handle(
            new OpenTicketArgs('Client says they paid but it still shows unpaid.', '#4471'),
            new ToolContext(user: $user, toolCallId: 'call_1'),
        );

        $ticket = Ticket::query()->where('number', $result->data['ticket_number'])->sole();
        $this->assertSame('T-10422', $ticket->number);
        $this->assertTrue($result->data['created']);
        $this->assertSame($user->customer_id, $ticket->customer_id);
        $this->assertSame($invoice->id, $ticket->invoice_id);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame('assistant', $ticket->source);
        $this->assertSame('Client says they paid but it still shows unpaid.', $ticket->body);
    }

    public function test_handle_does_not_open_a_second_ticket_for_a_retried_call(): void
    {
        $user = $this->customerUser();
        $args = new OpenTicketArgs('Client says they paid but it still shows unpaid.', null);
        $ctx = new ToolContext(user: $user, conversationId: 77, toolCallId: 'call_1');

        $first = (new OpenTicket)->handle($args, $ctx);
        $second = (new OpenTicket)->handle($args, $ctx);

        $this->assertSame($first->data['ticket_number'], $second->data['ticket_number']);
        $this->assertTrue($first->data['created']);
        $this->assertFalse($second->data['created']);
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_handle_keeps_the_same_call_id_from_two_users_apart(): void
    {
        $args = new OpenTicketArgs('Client says they paid but it still shows unpaid.', null);
        $first = $this->customerUser();
        $second = $this->customerUser();

        $one = (new OpenTicket)->handle($args, new ToolContext(user: $first, toolCallId: 'call_1'));
        $two = (new OpenTicket)->handle($args, new ToolContext(user: $second, toolCallId: 'call_1'));

        $this->assertNotSame($one->data['ticket_number'], $two->data['ticket_number']);
        $this->assertSame($second->customer_id, Ticket::query()->where('number', $two->data['ticket_number'])->sole()->customer_id);
    }

    public function test_handle_opens_separate_tickets_when_there_is_no_call_id(): void
    {
        $user = $this->customerUser();
        $args = new OpenTicketArgs('Client says they paid but it still shows unpaid.', null);
        Ticket::factory()->create(['customer_id' => $user->customer_id, 'idempotency_key' => null]);

        (new OpenTicket)->handle($args, new ToolContext(user: $user));
        (new OpenTicket)->handle($args, new ToolContext(user: $user));

        $this->assertSame(3, Ticket::query()->count());
    }

    public function test_handle_refuses_an_invoice_that_belongs_to_another_customer(): void
    {
        $owner = $this->customerUser();
        $this->invoiceFor($owner);
        $intruder = $this->customerUser();

        $result = (new OpenTicket)->handle(
            new OpenTicketArgs('Please look at this invoice for me.', '4471'),
            new ToolContext(user: $intruder, toolCallId: 'call_1'),
        );

        $this->assertFalse($result->ok);
        $this->assertSame('invoice not found', $result->error);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_validate_requires_a_meaningful_summary(): void
    {
        $this->expectException(ValidationException::class);

        (new OpenTicket)->validate(['summary' => 'hi', 'invoice_number' => null]);
    }
}
