<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_customers(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('customers');
    }

    public function test_customer_list_shows_customers(): void
    {
        Customer::factory()->create(['name' => 'Acme Studios']);

        $response = $this->get('customers');

        $response->assertOk();
        $response->assertSee('Acme Studios');
    }

    public function test_customer_page_lists_their_invoices(): void
    {
        $invoice = Invoice::factory()->overdueBy(10)->create();

        $response = $this->get("customers/{$invoice->customer_id}");

        $response->assertOk();
        $response->assertSee($invoice->number);
    }

    public function test_invoice_list_filters_by_status(): void
    {
        $overdue = Invoice::factory()->overdueBy(10)->create();
        $paid = Invoice::factory()->paidDaysAgo(10)->create();

        $response = $this->get('invoices?status=overdue');

        $response->assertOk();
        $response->assertSee($overdue->number);
        $response->assertDontSee($paid->number);
    }

    public function test_invoice_page_shows_late_fee_after_grace_period(): void
    {
        $invoice = Invoice::factory()->overdueBy(60)->create();

        $response = $this->get("invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Late fee so far');
    }

    public function test_missing_invoice_returns_not_found(): void
    {
        $response = $this->get('invoices/999999');

        $response->assertNotFound();
    }

    public function test_inbox_lists_tickets(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->get('inbox');

        $response->assertOk();
        $response->assertSee($ticket->number);
    }

    public function test_ticket_page_shows_body(): void
    {
        $ticket = Ticket::factory()->create(['body' => 'The PDF button does nothing.']);

        $response = $this->get("inbox/{$ticket->id}");

        $response->assertOk();
        $response->assertSee('The PDF button does nothing.');
    }
}
