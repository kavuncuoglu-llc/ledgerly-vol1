<?php

namespace Tests\Unit\Ai\Schemas;

use App\Ai\Schemas\InvoiceData;
use App\Ai\Schemas\LineItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Tests\TestCase;

class InvoiceDataTest extends TestCase
{
    public function test_rules_accept_a_consistent_invoice(): void
    {
        $validator = $this->validatorFor($this->invoice());

        $this->assertTrue($validator->passes());
    }

    public function test_rules_reject_a_date_in_the_wrong_format(): void
    {
        $validator = $this->validatorFor($this->invoice(['invoice_date' => '03/15/2026']));

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('invoice_date'));
    }

    public function test_rules_reject_a_due_date_before_the_invoice_date(): void
    {
        $validator = $this->validatorFor($this->invoice(['due_date' => '2026-03-01']));

        $this->assertTrue($validator->errors()->has('due_date'));
    }

    public function test_rules_accept_a_due_date_when_the_invoice_date_is_null(): void
    {
        $validator = $this->validatorFor($this->invoice(['invoice_date' => null]));

        $this->assertTrue($validator->passes());
    }

    public function test_rules_reject_a_currency_that_is_not_an_iso_code(): void
    {
        $validator = $this->validatorFor($this->invoice(['currency' => 'USA']));

        $this->assertTrue($validator->errors()->has('currency'));
    }

    public function test_rules_reject_a_numeric_string_with_a_thousands_separator(): void
    {
        $validator = $this->validatorFor($this->invoice(['total' => '1,284.50']));

        $this->assertTrue($validator->errors()->has('total'));
    }

    public function test_rules_reject_more_than_two_hundred_line_items(): void
    {
        $lines = array_fill(0, 201, ['description' => 'x', 'quantity' => 1, 'unit_price' => 0, 'amount' => 0]);

        $validator = $this->validatorFor($this->invoice(['line_items' => $lines, 'subtotal' => 0, 'total' => 0]));

        $this->assertTrue($validator->errors()->has('line_items'));
    }

    public function test_after_rules_flag_a_subtotal_that_differs_from_the_line_items(): void
    {
        $validator = $this->validatorFor($this->invoice(['subtotal' => 1204.50, 'total' => 1204.50]));

        $this->assertSame(['Line items sum to 1284.50, not 1204.50.'], $validator->errors()->get('subtotal'));
    }

    public function test_after_rules_flag_a_total_that_is_not_subtotal_plus_tax(): void
    {
        $validator = $this->validatorFor($this->invoice(['total' => 1204.50]));

        $this->assertSame(
            ['Total 1204.50 does not equal 1284.50 (subtotal plus tax).'],
            $validator->errors()->get('total'),
        );
    }

    public function test_after_rules_check_the_total_against_the_line_items_when_the_subtotal_is_missing(): void
    {
        $validator = $this->validatorFor($this->invoice(['subtotal' => null, 'tax' => null, 'total' => 1204.50]));

        $this->assertSame(
            ['Total 1204.50 does not equal 1284.50 (the line items plus tax).'],
            $validator->errors()->get('total'),
        );
    }

    public function test_after_rules_tolerate_a_one_cent_rounding_difference(): void
    {
        $validator = $this->validatorFor($this->invoice(['total' => 1284.51]));

        $this->assertTrue($validator->passes());
    }

    public function test_after_rules_do_not_check_provenance_without_source_text(): void
    {
        $validator = $this->validatorFor($this->invoice());

        $this->assertTrue($validator->passes());
    }

    public function test_after_rules_flag_a_total_that_does_not_appear_in_the_source_text(): void
    {
        $validator = $this->validatorFor($this->invoice(), sourceText: 'Tax 0.00 Total 999.00');

        $this->assertSame(
            ['total 1284.5 does not appear in the document.'],
            $validator->errors()->get('total'),
        );
    }

    public function test_after_rules_accept_numbers_that_appear_in_the_source_text(): void
    {
        $validator = $this->validatorFor($this->invoice(), sourceText: 'Subtotal 1,284.50 Tax 0.00 Total 1,284.50');

        $this->assertTrue($validator->passes());
    }

    public function test_from_array_builds_line_items_and_casts_amounts_to_float(): void
    {
        $invoice = InvoiceData::fromArray($this->invoice(['total' => 1284, 'tax' => 0]));

        $this->assertInstanceOf(LineItem::class, $invoice->line_items[0]);
        $this->assertSame(1284.0, $invoice->total);
        $this->assertSame(0.0, $invoice->tax);
        $this->assertFalse($invoice->meta->repaired);
    }

    public function test_from_array_keeps_null_fields_null(): void
    {
        $invoice = InvoiceData::fromArray($this->invoice(['due_date' => null, 'subtotal' => null]));

        $this->assertNull($invoice->due_date);
        $this->assertNull($invoice->subtotal);
    }

    private function validatorFor(array $data, string $sourceText = ''): ValidationValidator
    {
        $validator = Validator::make($data, InvoiceData::rules());

        foreach (InvoiceData::afterRules($sourceText) as $after) {
            $validator->after($after);
        }

        $validator->passes();

        return $validator;
    }

    private function invoice(array $overrides = []): array
    {
        return [
            'vendor_name' => 'Northgate Paper Co',
            'invoice_number' => 'NP-20931',
            'invoice_date' => '2026-03-16',
            'due_date' => '2026-04-15',
            'currency' => 'USD',
            'line_items' => [
                ['description' => 'A4 copy paper', 'quantity' => 40, 'unit_price' => 14.50, 'amount' => 580.00],
                ['description' => 'Toner cartridge', 'quantity' => 3, 'unit_price' => 89.50, 'amount' => 268.50],
                ['description' => 'Ring binders', 'quantity' => 120, 'unit_price' => 2.50, 'amount' => 300.00],
                ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 136.00, 'amount' => 136.00],
            ],
            'subtotal' => 1284.50,
            'tax' => 0,
            'total' => 1284.50,
            'error' => null,
            ...$overrides,
        ];
    }
}
