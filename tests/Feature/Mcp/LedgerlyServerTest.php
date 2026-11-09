<?php

namespace Tests\Feature\Mcp;

use App\Ai\Tools\ToolRegistry;
use App\Mcp\McpToolAdapter;
use App\Mcp\Prompts\SupportPromptMcp;
use App\Mcp\Prompts\TicketClassifyPromptMcp;
use App\Mcp\Resources\CustomerInvoicesTemplate;
use App\Mcp\Resources\HelpArticleResource;
use App\Mcp\Servers\Ledgerly;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Support\HelpCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class LedgerlyServerTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_it_serves_the_registrys_native_tools_with_the_same_schemas(): void
    {
        $this->assertSame(['lookup_invoice', 'list_invoices', 'open_ticket', 'account_status'], app(ToolRegistry::class)->native()->map->name()->all());
        $lookup = $this->adapter('lookup_invoice')->toArray();
        $this->assertSame(app(ToolRegistry::class)->get('lookup_invoice')->parameters(), $lookup['inputSchema']);
        $this->assertTrue($lookup['annotations']['readOnlyHint']);
        $this->assertFalse($this->adapter('open_ticket')->toArray()['annotations']['readOnlyHint']);
    }

    public function test_a_tool_call_runs_through_the_gate_scoped_to_the_token_owner_and_is_audited_as_mcp(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);

        $response = Ledgerly::actingAs($user)->tool($this->adapter('lookup_invoice'), [
            'invoice_number' => '#4471',
            'month' => null,
        ]);

        $response->assertSee('INV-4471');
        $audit = ToolCall::query()->sole();
        $this->assertSame('lookup_invoice', $audit->tool);
        $this->assertSame('mcp', $audit->source);
        $this->assertSame('allowed', $audit->decision);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('stdio', $audit->token_name);
        $this->assertStringStartsWith('mcp_', $audit->tool_call_id);
    }

    public function test_a_tool_call_cannot_reach_another_customers_invoice(): void
    {
        $owner = $this->customerUser();
        $this->invoiceFor($owner);
        $intruder = $this->customerUser();

        $response = Ledgerly::actingAs($intruder)->tool($this->adapter('lookup_invoice'), [
            'invoice_number' => '4471',
            'month' => null,
        ]);

        $response->assertSee('"found":false');
        $response->assertDontSee('INV-4471');
    }

    public function test_invalid_arguments_come_back_as_an_error_and_are_audited(): void
    {
        $user = $this->customerUser();

        $response = Ledgerly::actingAs($user)->tool($this->adapter('lookup_invoice'), [
            'invoice_number' => null,
            'month' => null,
        ]);

        $response->assertSee('invalid arguments');
        $this->assertSame('invalid', ToolCall::query()->sole()->decision);
    }

    public function test_a_low_risk_write_runs_and_a_high_risk_write_returns_the_approval_url(): void
    {
        $user = $this->customerUser();
        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)->add(new RefundTool));

        $response = Ledgerly::actingAs($user)->tool($this->adapter('issue_refund'), [
            'invoice' => '4471',
            'amount' => 21.6,
        ]);

        $response->assertSee('Awaiting approval in Ledgerly: ');
        $this->assertSame('pending_approval', ToolCall::query()->sole()->decision);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_a_help_article_resource_returns_the_markdown(): void
    {
        $user = $this->customerUser();
        $article = app(HelpCenter::class)->all()->first();

        $response = Ledgerly::actingAs($user)->resource(HelpArticleResource::class, [
            'category' => $article->category,
            'slug' => $article->slug,
        ]);

        $response->assertSee("# {$article->title}");
    }

    public function test_an_unknown_help_article_is_an_error(): void
    {
        $response = Ledgerly::actingAs($this->customerUser())->resource(HelpArticleResource::class, [
            'category' => 'billing',
            'slug' => 'no-such-article',
        ]);

        $response->assertSee('Help article not found.');
    }

    public function test_the_invoice_resource_lists_only_the_token_owners_customer(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);

        $response = Ledgerly::actingAs($user)->resource(CustomerInvoicesTemplate::class, ['id' => $user->customer_id]);

        $response->assertSee('INV-4471');
    }

    public function test_the_invoice_resource_does_not_resolve_another_customers_id(): void
    {
        $owner = $this->customerUser();
        $this->invoiceFor($owner);
        $intruder = $this->customerUser();

        $response = Ledgerly::actingAs($intruder)->resource(CustomerInvoicesTemplate::class, ['id' => $owner->customer_id]);

        $response->assertSee('Customer not found.');
        $response->assertDontSee('INV-4471');
    }

    public function test_it_reuses_the_apps_versioned_prompts(): void
    {
        $user = $this->customerUser();

        $support = Ledgerly::actingAs($user)->prompt(SupportPromptMcp::class, ['question' => 'What is a late fee?']);
        $classify = Ledgerly::actingAs($user)->prompt(TicketClassifyPromptMcp::class, ['message' => 'The export button is broken']);

        $support->assertSee('You are the support assistant for Ledgerly');
        $support->assertSee('What is a late fee?');
        $classify->assertSee('Reply with the category word only');
        $classify->assertSee('The export button is broken');
    }

    private function adapter(string $name): McpToolAdapter
    {
        return new McpToolAdapter(app(ToolRegistry::class)->get($name));
    }
}
