<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\ListInvoices\ListInvoicesArgs;
use App\Ai\Tools\ToolContext;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
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

        $result = (new ListInvoices)->handle(new ListInvoicesArgs(InvoiceStatus::Overdue, null), new ToolContext(user: $user));

        $this->assertSame(2, $result->data['count']);
        $this->assertEqualsCanonicalizing(['INV-1', 'INV-2'], array_column($result->data['invoices'], 'number'));
    }

    public function test_handle_never_includes_another_customers_invoices(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-1');
        $other = $this->customerUser();
        $this->invoiceFor($other, 'INV-2');

        $result = (new ListInvoices)->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertSame(['INV-1'], array_column($result->data['invoices'], 'number'));
    }

    public function test_handle_caps_the_list_and_says_when_more_exist(): void
    {
        $user = $this->customerUser();
        Invoice::factory()->count(ListInvoices::MAX_INVOICES + 3)->create(['customer_id' => $user->customer_id]);

        $result = (new ListInvoices)->handle(new ListInvoicesArgs(null, null), new ToolContext(user: $user));

        $this->assertSame(ListInvoices::MAX_INVOICES, $result->data['count']);
        $this->assertTrue($result->data['more_available']);
        $this->assertCount(ListInvoices::MAX_INVOICES, $result->data['invoices']);
    }

    public function test_validate_rejects_an_unknown_status(): void
    {
        $this->expectException(ValidationException::class);

        (new ListInvoices)->validate(['status' => 'bogus', 'month' => null]);
    }

    public function test_the_schema_offers_the_status_enum_plus_null(): void
    {
        $status = (new ListInvoices)->parameters()['properties']['status'];

        $this->assertSame(['string', 'null'], $status['type']);
        $this->assertSame(['draft', 'sent', 'paid', 'overdue', 'void', null], $status['enum']);
    }
}
