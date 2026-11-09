<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Exceptions\UnreadableInvoice;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Structured\StructuredClient;
use App\Ai\Vision\ImageEncoder;
use App\Ai\Vision\InvoicePrompt;
use Illuminate\Console\Command;

class InvoiceRead extends Command
{
    private const MISSING = '---';

    protected $signature = 'invoice:read {path : An invoice as a PDF or an image}';

    protected $description = 'Read a supplier invoice with a vision model into a validated InvoiceData, repairing it once if validation fails';

    public function handle(ImageEncoder $images, StructuredClient $structured): int
    {
        try {
            $invoice = $structured->extract(
                class: InvoiceData::class,
                messages: InvoicePrompt::messages($images->dataUrl((string) $this->argument('path'))),
                options: [
                    'model' => config('ai.vision_model'),
                ],
                feature: 'invoice.extract',
            );
        } catch (UnreadableInvoice|ModelRequestFailed|StructuredOutputFailed|StructuredRefusal $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->showTable($invoice);

        return Command::SUCCESS;
    }

    private function showTable(InvoiceData $invoice): void
    {
        $this->table(
            ['field', 'value'],
            [
                ['vendor', $invoice->vendor_name ?? self::MISSING],
                ['invoice #', $invoice->invoice_number ?? self::MISSING],
                ['due', $invoice->due_date ?? self::MISSING],
                ['total', $this->formatAmount($invoice->total)],
                ['lines', count($invoice->line_items)],
                ['repaired', $invoice->meta->repaired ? 'yes' : 'no'],
            ],
        );
    }

    private function formatAmount(?float $amount): string
    {
        if ($amount === null) {
            return self::MISSING;
        }

        return number_format($amount, 2);
    }
}
