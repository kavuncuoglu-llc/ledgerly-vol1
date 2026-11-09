<?php

namespace App\Ai\Shapes;

use App\Ai\Encoding\Shape;
use App\Models\Invoice;
use Illuminate\Support\Collection;

final class InvoiceSummaryShape implements Shape
{
    public const DEFAULT_LIMIT = 25;

    public function __construct(private readonly int $limit = self::DEFAULT_LIMIT) {}

    public function fields(): array
    {
        return [
            'number' => fn (Invoice $invoice): string => $invoice->number,
            'status' => fn (Invoice $invoice): string => $invoice->status->value,
            'due' => fn (Invoice $invoice): string => (string) $invoice->due_at?->toDateString(),
            'total' => fn (Invoice $invoice): float => round((float) $invoice->total, 2),
            'days_overdue' => fn (Invoice $invoice): int => $invoice->daysOverdue(),
            'late_fee' => fn (Invoice $invoice): float => $invoice->lateFee(),
        ];
    }

    public function header(Collection $rows): string
    {
        $today = today()->toDateString();

        $currency = $rows->first()?->currency;

        if ($currency === null) {
            return "Today is {$today}.";
        }

        return "Amounts in {$currency}. Today is {$today}.";
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
