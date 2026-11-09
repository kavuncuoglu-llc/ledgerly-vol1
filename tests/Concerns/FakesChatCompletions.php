<?php

namespace Tests\Concerns;

trait FakesChatCompletions
{
    protected function completion(string $content, int $promptTokens = 120, int $completionTokens = 40): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
        ];
    }

    protected function ticketJson(
        string $category = 'other',
        ?string $invoiceNumber = null,
        ?string $customerEmail = null,
        ?float $amountMentioned = null,
        ?string $dateMentioned = null,
    ): string {
        return json_encode([
            'category' => $category,
            'invoice_number' => $invoiceNumber,
            'customer_email' => $customerEmail,
            'amount_mentioned' => $amountMentioned,
            'date_mentioned' => $dateMentioned,
        ]);
    }

    protected function invoiceJson(float $total = 1284.50, ?string $error = null, ?float $subtotal = 1284.50): string
    {
        return json_encode([
            'vendor_name' => 'Northgate Paper Co',
            'invoice_number' => 'NP-20931',
            'invoice_date' => '2026-03-16',
            'due_date' => '2026-04-15',
            'currency' => 'USD',
            'line_items' => [
                ['description' => 'A4 copy paper', 'quantity' => 40, 'unit_price' => 14.50, 'amount' => 580.00],
                ['description' => 'Toner cartridge', 'quantity' => 3, 'unit_price' => 89.50, 'amount' => 268.50],
                ['description' => 'Ring binders', 'quantity' => 120, 'unit_price' => 2.50, 'amount' => 300.00],
                ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 136.00, 'amount' => 136.00],
            ],
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $total,
            'error' => $error,
        ]);
    }
}
