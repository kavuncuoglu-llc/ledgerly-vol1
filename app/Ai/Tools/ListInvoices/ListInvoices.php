<?php

namespace App\Ai\Tools\ListInvoices;

use App\Ai\Encoding\DataEncoder;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

final class ListInvoices implements Tool
{
    use DtoBackedTool;

    public function __construct(private readonly DataEncoder $encoder) {}

    public const DEFAULT_MONTHS = 6;

    public const NO_MATCHES = 'No invoices match.';

    public function name(): string
    {
        return 'list_invoices';
    }

    public function arguments(): string
    {
        return ListInvoicesArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function description(): string
    {
        return 'List the current customer\'s invoices, newest first, optionally only one status or one month (YYYY-MM); otherwise the last 6 months, or the number of months given. Returns a table with number, status, due date, total, days overdue, and late fee for up to 25 invoices. Use when the customer asks about several invoices at once, such as which ones are overdue. Do not use to look up one specific invoice; use lookup_invoice for that.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoices = $this->scopedQuery($args, $ctx)
            ->with('customer')
            ->latest('issued_at')
            ->get();

        if ($invoices->isEmpty()) {
            return ToolResult::text(self::NO_MATCHES);
        }

        return ToolResult::text($this->encoder->encode(
            rows: $invoices,
            shape: new InvoiceSummaryShape,
            format: config('ai.encoding.features.list_invoices'),
            key: 'invoices',
        ));
    }

    private function scopedQuery(ListInvoicesArgs $args, ToolContext $ctx): Builder
    {
        return Invoice::query()
            ->where('customer_id', $ctx->customer()->id)
            ->when($args->status, fn (Builder $query) => $query->where('status', $args->status))
            ->when($args->month, fn (Builder $query) => $query->whereYearMonth('issued_at', $args->month))
            ->unless($args->month, fn (Builder $query) => $query->where('issued_at', '>=', now()->subMonths($args->months ?? self::DEFAULT_MONTHS)));
    }
}
