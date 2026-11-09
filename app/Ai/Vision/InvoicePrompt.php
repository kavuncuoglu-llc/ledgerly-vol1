<?php

namespace App\Ai\Vision;

final class InvoicePrompt
{
    public static function system(): string
    {
        return "You extract data from invoice images. Respond only with JSON matching the schema.\nIf a field is not visible, use null. Never guess.";
    }

    public static function extract(): string
    {
        return 'Extract vendor_name, invoice_number, invoice_date, due_date, currency, '
            .'line_items (description, quantity, unit_price, amount), subtotal, tax, total. '
            .'If the image cannot be read at all, set error to a short reason and leave the rest null.';
    }

    public static function messages(string $dataUrl): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => self::extract(),
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $dataUrl,
                        ],
                    ],
                ],
            ],
        ];
    }
}
