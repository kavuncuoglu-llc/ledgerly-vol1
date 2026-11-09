<?php

namespace Tests\Unit\Ai\Vision;

use App\Ai\Vision\ExtractedInvoice;
use PHPUnit\Framework\TestCase;

class ExtractedInvoiceTest extends TestCase
{
    public function test_display_returns_dashes_for_a_missing_or_null_field(): void
    {
        $invoice = new ExtractedInvoice(['due_date' => null]);

        $this->assertSame('---', $invoice->display('due_date'));
        $this->assertSame('---', $invoice->display('vendor_name'));
    }

    public function test_display_returns_the_field_as_a_string(): void
    {
        $invoice = new ExtractedInvoice(['invoice_number' => 'NP-20931']);

        $this->assertSame('NP-20931', $invoice->display('invoice_number'));
    }

    public function test_line_items_total_sums_the_amounts(): void
    {
        $invoice = new ExtractedInvoice($this->data(total: 1284.50));

        $this->assertEqualsWithDelta(1284.50, $invoice->lineItemsTotal(), 0.001);
    }

    public function test_line_items_total_is_zero_without_line_items(): void
    {
        $this->assertSame(0.0, (new ExtractedInvoice([]))->lineItemsTotal());
    }

    public function test_totals_agree_when_the_model_total_matches_the_lines(): void
    {
        $this->assertTrue((new ExtractedInvoice($this->data(total: 1284.50)))->totalsAgree());
    }

    public function test_totals_disagree_when_a_digit_was_misread(): void
    {
        $this->assertFalse((new ExtractedInvoice($this->data(total: 1204.50)))->totalsAgree());
    }

    public function test_totals_agree_reads_a_formatted_string_total(): void
    {
        $invoice = new ExtractedInvoice($this->data(total: '$1,284.50'));

        $this->assertTrue($invoice->totalsAgree());
        $this->assertSame('1,284.50', $invoice->displayTotal());
    }

    public function test_totals_disagree_when_the_model_returned_no_total(): void
    {
        $invoice = new ExtractedInvoice($this->data(total: null));

        $this->assertFalse($invoice->totalsAgree());
        $this->assertSame('---', $invoice->displayTotal());
    }

    private function data(int|float|string|null $total): array
    {
        return [
            'line_items' => [
                ['description' => 'Paper', 'amount' => 580.00],
                ['description' => 'Toner', 'amount' => '268.50'],
                ['description' => 'Binders', 'amount' => 300],
                ['description' => 'Delivery', 'amount' => 136.0],
            ],
            'total' => $total,
        ];
    }
}
