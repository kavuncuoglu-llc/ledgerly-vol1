<?php

namespace Tests\Unit\Ai\Shapes;

use App\Ai\Shapes\InvoiceSummaryShape;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceSummaryShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fields_select_only_what_a_support_answer_uses(): void
    {
        $this->assertSame(
            ['number', 'status', 'due', 'total', 'days_overdue', 'late_fee'],
            array_keys((new InvoiceSummaryShape)->fields()),
        );
    }

    public function test_fields_format_dates_amounts_and_computed_values(): void
    {
        $invoice = $this->overdueInvoice();

        $row = array_map(fn (callable $field) => $field($invoice), (new InvoiceSummaryShape)->fields());

        $this->assertSame('INV-4471', $row['number']);
        $this->assertSame('overdue', $row['status']);
        $this->assertSame('2026-04-02', $row['due']);
        $this->assertSame(1284.5, $row['total']);
        $this->assertSame(18, $row['days_overdue']);
        $this->assertSame(0.0, $row['late_fee']);
    }

    public function test_header_states_the_currency_and_today_once(): void
    {
        $header = (new InvoiceSummaryShape)->header(collect([$this->overdueInvoice()]));

        $this->assertSame('Amounts in USD. Today is 2026-04-20.', $header);
    }

    public function test_header_without_rows_still_states_today(): void
    {
        $this->assertSame('Today is 2026-04-20.', (new InvoiceSummaryShape)->header(collect()));
    }

    public function test_limit_defaults_to_twenty_five_and_can_be_raised(): void
    {
        $this->assertSame(25, (new InvoiceSummaryShape)->limit());
        $this->assertSame(120, (new InvoiceSummaryShape(limit: 120))->limit());
    }

    private function overdueInvoice(): Invoice
    {
        $invoice = (new Invoice)->forceFill([
            'number' => 'INV-4471',
            'status' => InvoiceStatus::Overdue,
            'currency' => 'USD',
            'total' => '1284.50',
            'due_at' => '2026-04-02 00:00:00',
            'is_disputed' => false,
            'has_payment_plan' => false,
        ]);

        return $invoice->setRelation('customer', (new Customer)->forceFill([
            'late_fee_enabled' => true,
            'late_fee_percent' => 1.5,
        ]));
    }
}
