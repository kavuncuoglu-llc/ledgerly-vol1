<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Exceptions\DuplicateTool;
use App\Ai\Exceptions\UnknownTool;
use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolSource;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    public function test_definitions_emit_the_chat_completions_tools_array(): void
    {
        $registry = (new ToolRegistry)->add(new LookupInvoice);

        $definitions = $registry->definitions();

        $this->assertSame('function', $definitions[0]['type']);
        $this->assertSame('lookup_invoice', $definitions[0]['function']['name']);
        $this->assertStringContainsString('Do not use for general questions', $definitions[0]['function']['description']);
        $this->assertSame(['invoice_number', 'month'], $definitions[0]['function']['parameters']['required']);
    }

    public function test_get_throws_for_an_unknown_name(): void
    {
        $this->expectException(UnknownTool::class);

        (new ToolRegistry)->get('drop_tables');
    }

    public function test_add_refuses_two_tools_with_one_name(): void
    {
        $this->expectException(DuplicateTool::class);

        (new ToolRegistry)->add(new LookupInvoice)->add(new LookupInvoice);
    }

    public function test_native_leaves_out_tools_that_came_from_a_source(): void
    {
        $registry = (new ToolRegistry)
            ->add(new LookupInvoice)
            ->addSource(new class implements ToolSource
            {
                public function tools(): array
                {
                    return [new ListInvoices];
                }
            });

        $this->assertSame(['lookup_invoice'], $registry->native()->map->name()->all());
        $this->assertSame(['lookup_invoice', 'list_invoices'], $registry->all()->map->name()->all());
        $this->assertTrue($registry->has('list_invoices'));
    }

    public function test_a_source_cannot_shadow_a_native_tool(): void
    {
        $registry = (new ToolRegistry)
            ->add(new LookupInvoice)
            ->addSource(new class implements ToolSource
            {
                public function tools(): array
                {
                    return [new LookupInvoice];
                }
            });

        $this->expectException(DuplicateTool::class);

        $registry->all();
    }

    public function test_has_high_risk_tools_reports_the_kinds_registered(): void
    {
        $this->assertFalse((new ToolRegistry)->add(new LookupInvoice)->hasHighRiskTools());
        $this->assertTrue((new ToolRegistry)->add(new RefundTool)->hasHighRiskTools());
    }
}
