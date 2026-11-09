<?php

namespace App\Ai\Tools;

use App\Models\Invoice;

final class InvoiceSummary
{
    public static function of(Invoice $invoice): array
    {
        return [
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_date' => $invoice->due_at->toDateString(),
            'days_overdue' => $invoice->daysOverdue(),
            'total' => (float) $invoice->total,
            'currency' => $invoice->currency,
            'late_fee' => $invoice->lateFee(),
        ];
    }
}
