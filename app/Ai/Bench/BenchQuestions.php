<?php

namespace App\Ai\Bench;

use App\Ai\Exceptions\UnusableBenchmark;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Collection;

final class BenchQuestions
{
    public const MINIMUM_ROWS = 10;

    private const LARGE_TOTAL = 1000;

    public static function for(Collection $invoices): array
    {
        if ($invoices->count() < self::MINIMUM_ROWS) {
            throw UnusableBenchmark::tooFewRows($invoices->count(), self::MINIMUM_ROWS);
        }

        $overdue = $invoices->filter(fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Overdue)->values();

        if ($overdue->isEmpty()) {
            throw UnusableBenchmark::noOverdueInvoices();
        }

        return [
            ...self::rankingQuestions($invoices, $overdue),
            ...self::countQuestions($invoices, $overdue),
            ...self::lookupQuestions($invoices),
            ...self::overdueQuestions($overdue),
        ];
    }

    private static function rankingQuestions(Collection $invoices, Collection $overdue): array
    {
        return [
            self::question('Which invoice has the largest total?', $invoices->sortByDesc(fn (Invoice $invoice): float => (float) $invoice->total)->first()->number),
            self::question('Which invoice has the smallest total?', $invoices->sortBy(fn (Invoice $invoice): float => (float) $invoice->total)->first()->number),
            self::question('Which invoice has the earliest due date?', $invoices->sortBy(fn (Invoice $invoice): string => $invoice->due_at->toDateString())->first()->number),
            self::question('Which overdue invoice has the most days overdue?', $overdue->sortByDesc(fn (Invoice $invoice): int => $invoice->daysOverdue())->first()->number),
        ];
    }

    private static function countQuestions(Collection $invoices, Collection $overdue): array
    {
        return [
            self::question('How many invoices are overdue?', (string) $overdue->count()),
            self::question('How many invoices are paid?', self::countWithStatus($invoices, InvoiceStatus::Paid)),
            self::question('How many invoices are void?', self::countWithStatus($invoices, InvoiceStatus::Void)),
            self::question('How many invoices have the status sent?', self::countWithStatus($invoices, InvoiceStatus::Sent)),
            self::question('How many invoices have a total above '.self::LARGE_TOTAL.'?', (string) $invoices->filter(fn (Invoice $invoice): bool => (float) $invoice->total > self::LARGE_TOTAL)->count()),
            self::question('How many invoices were issued in '.self::issuedMonth($invoices->first()).'?', (string) $invoices->filter(fn (Invoice $invoice): bool => self::issuedMonth($invoice) === self::issuedMonth($invoices->first()))->count()),
            self::question('What is the combined total of the overdue invoices?', self::amount($overdue->sum(fn (Invoice $invoice): float => (float) $invoice->total))),
        ];
    }

    private static function lookupQuestions(Collection $invoices): array
    {
        $middle = $invoices->get(intdiv($invoices->count(), 2));

        $last = $invoices->last();

        return [
            self::question("What is the due date of {$invoices[1]->number}?", $invoices[1]->due_at->toDateString()),
            self::question("What is the due date of {$middle->number}?", $middle->due_at->toDateString()),
            self::question("What is the due date of {$last->number}?", $last->due_at->toDateString()),
            self::question("What is the status of {$invoices[3]->number}?", $invoices[3]->status->value),
            self::question("What is the status of {$last->number}?", $last->status->value),
            self::question("What is the total of {$invoices[5]->number}?", self::amount((float) $invoices[5]->total)),
            self::question("What is the total of {$invoices[8]->number}?", self::amount((float) $invoices[8]->total)),
            self::question("Which invoice is due on {$invoices[7]->due_at->toDateString()}?", $invoices[7]->number),
        ];
    }

    private static function overdueQuestions(Collection $overdue): array
    {
        $first = $overdue->first();

        return [
            self::question("How many days overdue is {$first->number}?", (string) $first->daysOverdue()),
        ];
    }

    private static function countWithStatus(Collection $invoices, InvoiceStatus $status): string
    {
        return (string) $invoices->filter(fn (Invoice $invoice): bool => $invoice->status === $status)->count();
    }

    private static function issuedMonth(Invoice $invoice): string
    {
        return $invoice->issued_at->format('Y-m');
    }

    private static function amount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function question(string $question, string $expected): array
    {
        return [
            'question' => $question,
            'expected' => $expected,
        ];
    }
}
