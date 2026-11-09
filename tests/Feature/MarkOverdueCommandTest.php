<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkOverdueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sent_invoice_past_due_date_becomes_overdue(): void
    {
        $invoice = Invoice::factory()->sentDueIn(-3)->create();

        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Overdue, $invoice->fresh()->status);
    }

    public function test_sent_invoice_not_yet_due_stays_sent(): void
    {
        $invoice = Invoice::factory()->sentDueIn(3)->create();

        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_paid_and_draft_invoices_are_untouched(): void
    {
        $paid = Invoice::factory()->paidDaysAgo(60)->create();
        $draft = Invoice::factory()->draft()->create();

        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Paid, $paid->fresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
    }
}
