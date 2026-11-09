<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ToolRegistrySubsetTest extends TestCase
{
    public function test_only_keeps_the_named_tools(): void
    {
        $registry = (new ToolRegistry)->add(new LookupInvoice)->add(new OpenTicket)->add(new RefundTool);

        $subset = $registry->only(['open_ticket', 'lookup_invoice']);

        $this->assertSame(['lookup_invoice', 'open_ticket'], array_column(array_column($subset->definitions(), 'function'), 'name'));
        $this->assertFalse($subset->has('issue_refund'));
        $this->assertFalse($subset->hasHighRiskTools());
    }

    public function test_only_ignores_names_the_registry_does_not_have_and_leaves_the_original_alone(): void
    {
        $registry = (new ToolRegistry)->add(new LookupInvoice);

        $subset = $registry->only(['lookup_invoice', 'search_help_center']);

        $this->assertCount(1, $subset->all());
        $this->assertCount(1, $registry->all());
        $this->assertSame([], $registry->only([])->definitions());
    }

    public function test_write_kinds_are_writes_and_reads_are_not(): void
    {
        $this->assertFalse(ToolKind::Read->isWrite());
        $this->assertTrue(ToolKind::WriteLowRisk->isWrite());
        $this->assertTrue(ToolKind::WriteHighRisk->isWrite());
    }
}
