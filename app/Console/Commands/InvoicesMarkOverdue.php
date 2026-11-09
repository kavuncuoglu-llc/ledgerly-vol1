<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Console\Command;

class InvoicesMarkOverdue extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Mark sent invoices that are past their due date as overdue';

    public function handle(): int
    {
        $updated = $this->markPastDueInvoices();

        $this->info("Marked {$updated} invoices as overdue.");

        return Command::SUCCESS;
    }

    private function markPastDueInvoices(): int
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Sent)
            ->where('due_at', '<', now()->startOfDay())
            ->update([
                'status' => InvoiceStatus::Overdue,
            ]);
    }
}
