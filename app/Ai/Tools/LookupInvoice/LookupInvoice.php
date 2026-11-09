<?php

namespace App\Ai\Tools\LookupInvoice;

use App\Ai\Tools\CompactsResult;
use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\InvoiceNumber;
use App\Ai\Tools\InvoiceSummary;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Invoice;

final class LookupInvoice implements CompactsResult, Tool
{
    use DtoBackedTool;

    public function name(): string
    {
        return 'lookup_invoice';
    }

    public function arguments(): string
    {
        return LookupInvoiceArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function description(): string
    {
        return 'Look up one invoice for the current customer by invoice number or by month (YYYY-MM). Returns status, amounts, due date, and days overdue. Use whenever the customer asks about a specific invoice. Do not use for general questions about how Ledgerly works.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoice = $this->findInvoice($args, $ctx);

        if (! $invoice->exists) {
            return ToolResult::ok([
                'found' => false,
            ]);
        }

        return ToolResult::ok([
            'found' => true,
            'invoice' => InvoiceSummary::of($invoice),
        ]);
    }

    public function compactResult(string $content): string
    {
        $invoice = json_decode($content, true)['invoice'] ?? null;

        if (! is_array($invoice)) {
            return 'no matching invoice found';
        }

        return sprintf(
            'returned invoice %s: %s, %d days overdue, total %s %.2f, due %s',
            $invoice['number'],
            $invoice['status'],
            $invoice['days_overdue'],
            $invoice['currency'],
            $invoice['total'],
            $invoice['due_date'],
        );
    }

    private function findInvoice(LookupInvoiceArgs $args, ToolContext $ctx): Invoice
    {
        $query = $ctx->customer()->invoices()->with('customer');

        if ($args->invoice_number) {
            return $query
                ->where('number', InvoiceNumber::normalize($args->invoice_number))
                ->first() ?? new Invoice;
        }

        return $query
            ->whereYearMonth('issued_at', $args->month)
            ->latest('issued_at')
            ->first() ?? new Invoice;
    }
}
