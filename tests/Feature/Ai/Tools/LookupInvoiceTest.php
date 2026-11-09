<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Schema\SchemaBuilder;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\LookupInvoice\LookupInvoiceArgs;
use App\Ai\Tools\ToolContext;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class LookupInvoiceTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    #[DataProvider('writtenInvoiceNumbers')]
    public function test_handle_finds_the_invoice_however_the_customer_wrote_the_number(string $written): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);

        $result = (new LookupInvoice)->handle(new LookupInvoiceArgs($written, null), new ToolContext(user: $user));

        $this->assertTrue($result->data['found']);
        $this->assertSame('INV-4471', $result->data['invoice']['number']);
    }

    public static function writtenInvoiceNumbers(): array
    {
        return [
            'bare digits' => ['4471'],
            'hash prefix' => ['#4471'],
            'stored form' => ['INV-4471'],
            'lowercase stored form' => ['inv-4471'],
            'padded' => [' # 4471 '],
        ];
    }

    public function test_handle_returns_the_small_summary_the_model_needs(): void
    {
        $user = $this->customerUser();
        $invoice = $this->invoiceFor($user, 'INV-4471', 18);

        $result = (new LookupInvoice)->handle(new LookupInvoiceArgs('4471', null), new ToolContext(user: $user));

        $this->assertSame([
            'number' => 'INV-4471',
            'status' => 'overdue',
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_date' => $invoice->due_at->toDateString(),
            'days_overdue' => 18,
            'total' => (float) $invoice->total,
            'currency' => 'USD',
            'late_fee' => 0.0,
        ], $result->data['invoice']);
    }

    public function test_handle_reports_the_late_fee_once_the_grace_period_has_passed(): void
    {
        $user = $this->customerUser();
        $invoice = $this->invoiceFor($user, 'INV-4438', 41);

        $result = (new LookupInvoice)->handle(new LookupInvoiceArgs('4438', null), new ToolContext(user: $user));

        $expected = round((float) $invoice->total * 0.015 * (11 / 30), 2);
        $this->assertSame($expected, $result->data['invoice']['late_fee']);
    }

    public function test_handle_finds_the_latest_invoice_issued_in_a_month(): void
    {
        $user = $this->customerUser();
        Invoice::factory()->create(['customer_id' => $user->customer_id, 'number' => 'INV-100', 'issued_at' => '2026-03-02 09:00:00', 'due_at' => '2026-04-01']);
        Invoice::factory()->create(['customer_id' => $user->customer_id, 'number' => 'INV-101', 'issued_at' => '2026-03-20 09:00:00', 'due_at' => '2026-04-19']);
        Invoice::factory()->create(['customer_id' => $user->customer_id, 'number' => 'INV-102', 'issued_at' => '2026-04-05 09:00:00', 'due_at' => '2026-05-05']);

        $result = (new LookupInvoice)->handle(new LookupInvoiceArgs(null, '2026-03'), new ToolContext(user: $user));

        $this->assertSame('INV-101', $result->data['invoice']['number']);
    }

    public function test_handle_reports_not_found_for_an_unknown_invoice(): void
    {
        $user = $this->customerUser();

        $result = (new LookupInvoice)->handle(new LookupInvoiceArgs('9999', null), new ToolContext(user: $user));

        $this->assertTrue($result->ok);
        $this->assertSame(['found' => false], $result->data);
    }

    public function test_handle_never_returns_another_customers_invoice(): void
    {
        $owner = $this->customerUser();
        $this->invoiceFor($owner);
        $intruder = $this->customerUser();

        $byNumber = (new LookupInvoice)->handle(new LookupInvoiceArgs('4471', null), new ToolContext(user: $intruder));
        $byMonth = (new LookupInvoice)->handle(new LookupInvoiceArgs(null, now()->subDays(48)->format('Y-m')), new ToolContext(user: $intruder));

        $this->assertSame(['found' => false], $byNumber->data);
        $this->assertSame(['found' => false], $byMonth->data);
    }

    public function test_the_schema_has_no_customer_parameter(): void
    {
        $parameters = (new LookupInvoice)->parameters();

        $this->assertSame(['invoice_number', 'month'], array_keys($parameters['properties']));
        $this->assertFalse($parameters['additionalProperties']);
    }

    public function test_the_schema_matches_figure_eleven_two(): void
    {
        $this->assertSame([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['invoice_number', 'month'],
            'properties' => [
                'invoice_number' => [
                    'type' => ['string', 'null'],
                    'description' => 'Invoice number as the customer wrote it, e.g. 4471 or #4471. Null if they gave a month instead.',
                ],
                'month' => [
                    'type' => ['string', 'null'],
                    'description' => 'Month the customer referred to, as YYYY-MM. Null if they gave an invoice number.',
                ],
            ],
        ], SchemaBuilder::for(LookupInvoiceArgs::class));
    }

    #[DataProvider('invalidArguments')]
    public function test_validate_rejects_arguments_that_are_not_an_invoice_number_or_month(array $arguments): void
    {
        $this->expectException(ValidationException::class);

        (new LookupInvoice)->validate($arguments);
    }

    public static function invalidArguments(): array
    {
        return [
            'neither given' => [['invoice_number' => null, 'month' => null]],
            'a name' => [['invoice_number' => 'Acme Studios', 'month' => null]],
            'sql' => [['invoice_number' => '1; drop table invoices', 'month' => null]],
            'month in words' => [['invoice_number' => null, 'month' => 'March']],
            'month with a day' => [['invoice_number' => null, 'month' => '2026-03-15']],
        ];
    }

    public function test_validate_accepts_either_an_invoice_number_or_a_month(): void
    {
        $tool = new LookupInvoice;

        $this->assertSame(['invoice_number' => '#4471', 'month' => null], $tool->validate(['invoice_number' => '#4471', 'month' => null]));
        $this->assertSame(['invoice_number' => null, 'month' => '2026-03'], $tool->validate(['invoice_number' => null, 'month' => '2026-03']));
    }
}
