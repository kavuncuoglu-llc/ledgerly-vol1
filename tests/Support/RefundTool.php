<?php

namespace Tests\Support;

use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;

final class RefundTool implements Tool
{
    use DtoBackedTool;

    public int $executions = 0;

    public function name(): string
    {
        return 'issue_refund';
    }

    public function description(): string
    {
        return 'Refund a late fee.';
    }

    public function arguments(): string
    {
        return RefundArguments::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteHighRisk;
    }

    public function describeIntent(array $arguments, ToolContext $ctx): string
    {
        return "Refund \${$arguments['amount']} late fee on invoice {$arguments['invoice']}";
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $this->executions++;

        return ToolResult::ok([
            'refunded' => $args->amount,
        ]);
    }
}
