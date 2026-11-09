<?php

namespace Tests\Support;

use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\LookupInvoice\LookupInvoiceArgs;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use RuntimeException;

final class ExplodingTool implements Tool
{
    use DtoBackedTool;

    public function name(): string
    {
        return 'explode';
    }

    public function description(): string
    {
        return 'Always fails.';
    }

    public function arguments(): string
    {
        return LookupInvoiceArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        throw new RuntimeException('SQLSTATE[HY000] secret internals');
    }
}
