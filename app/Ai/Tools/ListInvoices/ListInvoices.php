<?php

namespace App\Ai\Tools\ListInvoices;

use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\InvoiceSummary;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

final class ListInvoices implements Tool
{
    use DtoBackedTool;

    public const MAX_INVOICES = 20;

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
        return 'List the current customer\'s invoices, newest first, optionally only one status or one month (YYYY-MM). Returns number, status, dates, days overdue, and amounts for up to 20 invoices. Use when the customer asks about several invoices at once, such as which ones are overdue. Do not use to look up one specific invoice; use lookup_invoice for that.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoices = $this->scopedQuery($args, $ctx)
            ->with('customer')
            ->latest('issued_at')
            ->limit(self::MAX_INVOICES + 1)
            ->get();

        return ToolResult::ok([
            'count' => min($invoices->count(), self::MAX_INVOICES),
            'more_available' => $invoices->count() > self::MAX_INVOICES,
            'invoices' => $invoices
                ->take(self::MAX_INVOICES)
                ->map(fn (Invoice $invoice): array => InvoiceSummary::of($invoice))
                ->values()
                ->all(),
        ]);
    }

    private function scopedQuery(ListInvoicesArgs $args, ToolContext $ctx): Builder
    {
        return Invoice::query()
            ->where('customer_id', $ctx->customer()->id)
            ->when($args->status, fn (Builder $query) => $query->where('status', $args->status))
            ->when($args->month, fn (Builder $query) => $query->whereYearMonth('issued_at', $args->month));
    }
}
