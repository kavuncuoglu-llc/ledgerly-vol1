<?php

namespace Tests\Unit\Models;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_days_overdue_returns_zero_for_paid_invoice(): void
    {
        $invoice = Invoice::factory()->paidDaysAgo(5)->create();

        $this->assertSame(0, $invoice->daysOverdue());
    }

    public function test_days_overdue_counts_days_since_due_date(): void
    {
        $invoice = Invoice::factory()->overdueBy(18)->create();

        $this->assertSame(18, $invoice->daysOverdue());
    }

    public function test_late_fee_is_zero_inside_the_thirty_day_grace_period(): void
    {
        $invoice = Invoice::factory()->overdueBy(30)->create();

        $this->assertSame(0.0, $invoice->lateFee());
    }

    public function test_late_fee_is_prorated_by_day_after_grace_period(): void
    {
        $invoice = Invoice::factory()
            ->for(Customer::factory()->state(['late_fee_percent' => 1.50]))
            ->overdueBy(45)
            ->amounts(1000.00, 0.00)
            ->create();

        $this->assertSame(7.5, $invoice->lateFee());
    }

    public function test_late_fee_is_zero_when_invoice_is_disputed(): void
    {
        $invoice = Invoice::factory()->overdueBy(60)->disputed()->create();

        $this->assertSame(0.0, $invoice->lateFee());
    }

    public function test_late_fee_is_zero_when_customer_has_late_fees_disabled(): void
    {
        $invoice = Invoice::factory()
            ->for(Customer::factory()->state(['late_fee_enabled' => false]))
            ->overdueBy(60)
            ->create();

        $this->assertSame(0.0, $invoice->lateFee());
    }

    public function test_where_year_month_scope_selects_invoices_issued_in_that_month(): void
    {
        $invoice = Invoice::factory()->overdueBy(40)->create();

        $month = $invoice->issued_at->format('Y-m');

        $found = Invoice::query()->whereYearMonth('issued_at', $month)->count();

        $this->assertSame(1, $found);
    }
}
