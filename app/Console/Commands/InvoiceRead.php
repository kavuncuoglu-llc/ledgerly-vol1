<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnreadableInvoice;
use App\Ai\Sampling\ChatSampler;
use App\Ai\Sampling\SampleResult;
use App\Ai\Vision\ExtractedInvoice;
use App\Ai\Vision\ImageEncoder;
use App\Ai\Vision\InvoicePrompt;
use Illuminate\Console\Command;

class InvoiceRead extends Command
{
    protected $signature = 'invoice:read {path : An invoice as a PDF or an image}';

    protected $description = 'Read a supplier invoice with a vision model and cross-check its total against the line items';

    public function handle(ImageEncoder $images, ChatSampler $sampler): int
    {
        try {
            $started = hrtime(true);

            $reply = $this->askVisionModel($images, $sampler, (string) $this->argument('path'));

            $seconds = (hrtime(true) - $started) / 1_000_000_000;

            $invoice = new ExtractedInvoice(InvoicePrompt::parse($reply->content));
        } catch (UnreadableInvoice|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->showTable($invoice);

        $this->line(sprintf('in %d out %d · %.1fs', $reply->promptTokens, $reply->completionTokens, $seconds));

        return Command::SUCCESS;
    }

    private function askVisionModel(ImageEncoder $images, ChatSampler $sampler, string $path): SampleResult
    {
        return $sampler->chat(
            messages: [
                [
                    'role' => 'system',
                    'content' => InvoicePrompt::system(),
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => InvoicePrompt::extract(),
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $images->dataUrl($path),
                            ],
                        ],
                    ],
                ],
            ],
            parameters: config('ai.profiles.deterministic'),
            model: config('ai.vision_model'),
        );
    }

    private function showTable(ExtractedInvoice $invoice): void
    {
        $this->table(
            ['field', 'value'],
            [
                ['vendor', $invoice->display('vendor_name')],
                ['invoice #', $invoice->display('invoice_number')],
                ['due', $invoice->display('due_date')],
                ['total (model)', $invoice->displayTotal()],
                ['total (lines)', number_format($invoice->lineItemsTotal(), 2)],
                ['agree?', $invoice->totalsAgree() ? 'yes' : 'NO --- review'],
            ],
        );
    }
}
