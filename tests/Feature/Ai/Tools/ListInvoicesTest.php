<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Encoding\DataEncoder;
use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\ListInvoices\ListInvoicesArgs;
use App\Ai\Tools\ToolContext;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use HelgeSverre\Toon\Toon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class ListInvoicesTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_handle_lists_only_the_requested_status(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-1', 18);
        $this->invoiceFor($user, 'INV-2', 41);
        Invoice::factory()->paidDaysAgo(3)->create(['customer_id' => $user->customer_id, 'number' => 'INV-3']);

        $result = $this->tool()->handle(new ListInvoicesArgs(InvoiceStatus::Overdue, null), new ToolContext(user: $user));

        $rows = $this->decodedRows($result->text);
        $this->assertEqualsCanonicalizing(['INV-1', 'INV-2'], array_column($rows, 'number'));
    }

    public function test_handle_never_includes_another_customers_invoices(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-1');
        $other = $this->customerUser();
        $this->invoiceFor($other, 'INV-2');

        $result = $this->tool()->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertSame(['INV-1'], array_column($this->decodedRows($result->text), 'number'));
        $this->assertStringNotContainsString('INV-2', $result->text);
    }

    public function test_handle_returns_text_in_the_configured_encoding(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471', 18);
        config()->set('ai.encoding.default', 'tsv');

        $result = $this->tool()->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertTrue($result->ok);
        $this->assertStringContainsString("number\tstatus\tdue\ttotal\tdays_overdue\tlate_fee", $result->text);
        $this->assertStringContainsString('INV-4471', $result->text);
    }

    public function test_handle_uses_the_per_feature_format_over_the_default(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471', 18);
        config()->set('ai.encoding.default', 'tsv');
        config()->set('ai.encoding.features.list_invoices', 'json');

        $result = $this->tool()->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertStringContainsString('{"invoices":[{"number":"INV-4471"', $result->text);
    }

    public function test_handle_caps_the_list_and_says_how_many_were_cut(): void
    {
        $user = $this->customerUser();
        Invoice::factory()->count(28)->create(['customer_id' => $user->customer_id]);

        $result = $this->tool()->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertStringContainsString('Showing 25 of 28 rows.', $result->text);
        $this->assertCount(25, $this->decodedRows($result->text));
    }

    public function test_handle_leaves_out_invoices_older_than_the_months_window(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-RECENT');
        Invoice::factory()->create([
            'customer_id' => $user->customer_id,
            'number' => 'INV-OLD',
            'issued_at' => now()->subMonths(8),
            'due_at' => now()->subMonths(7),
        ]);

        $narrow = $this->tool()->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));
        $wide = $this->tool()->handle(new ListInvoicesArgs(null, null, 12), new ToolContext(user: $user));

        $this->assertStringNotContainsString('INV-OLD', $narrow->text);
        $this->assertStringContainsString('INV-OLD', $wide->text);
    }

    public function test_handle_filters_by_month_when_one_is_given(): void
    {
        $user = $this->customerUser();
        Invoice::factory()->create([
            'customer_id' => $user->customer_id,
            'number' => 'INV-MARCH',
            'issued_at' => '2026-03-03 09:14:00',
            'due_at' => '2026-04-02',
        ]);
        $this->invoiceFor($user, 'INV-NOW');

        $result = $this->tool()->handle(new ListInvoicesArgs(null, '2026-03'), new ToolContext(user: $user));

        $this->assertStringContainsString('INV-MARCH', $result->text);
        $this->assertStringNotContainsString('INV-NOW', $result->text);
    }

    public function test_handle_says_so_when_nothing_matches(): void
    {
        $user = $this->customerUser();

        $result = $this->tool()->handle(new ListInvoicesArgs(InvoiceStatus::Void, null), new ToolContext(user: $user));

        $this->assertTrue($result->ok);
        $this->assertSame(ListInvoices::NO_MATCHES, $result->text);
    }

    public function test_the_tool_message_is_the_encoded_table_not_json(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);

        $result = $this->tool()->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertSame($result->text, $result->toJson());
        $this->assertStringContainsString('invoices[1]{number,status,due,total,days_overdue,late_fee}:', $result->text);
    }

    public function test_validate_rejects_an_unknown_status(): void
    {
        $this->expectException(ValidationException::class);

        $this->tool()->validate(['status' => 'bogus', 'month' => null]);
    }

    public function test_validate_rejects_months_outside_the_allowed_range(): void
    {
        $this->expectException(ValidationException::class);

        $this->tool()->validate(['status' => null, 'month' => null, 'months' => 99]);
    }

    public function test_the_schema_offers_the_status_enum_plus_null(): void
    {
        $status = $this->tool()->parameters()['properties']['status'];

        $this->assertSame(['string', 'null'], $status['type']);
        $this->assertSame(['draft', 'sent', 'paid', 'overdue', 'void', null], $status['enum']);
    }

    public function test_the_schema_offers_a_nullable_integer_months(): void
    {
        $months = $this->tool()->parameters()['properties']['months'];

        $this->assertSame(['integer', 'null'], $months['type']);
    }

    private function tool(): ListInvoices
    {
        return new ListInvoices($this->app->make(DataEncoder::class));
    }

    private function decodedRows(string $text): array
    {
        $body = substr($text, (int) strpos($text, 'invoices['));

        return Toon::decode($body)['invoices'];
    }
}
