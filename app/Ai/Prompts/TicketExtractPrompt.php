<?php

namespace App\Ai\Prompts;

final class TicketExtractPrompt
{
    public const VERSION = '2026-11-09.1';

    public static function system(): string
    {
        return <<<'PROMPT'
You extract structured data from Ledgerly support messages.
Fields: category (billing, howto, bug, account or other), invoice_number (string or null),
customer_email (string or null), amount_mentioned (number or null), date_mentioned (ISO date or null).
Use null for any field not explicitly present. Never infer or guess.
A date without a year is null.
Output only a JSON object with exactly these keys.
PROMPT;
    }

    public static function messages(string $message): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => 'Our client billing@northwind.example was charged $89.00 on 2026-02-14 and wants a refund',
            ],
            [
                'role' => 'assistant',
                'content' => '{"category":"billing","invoice_number":null,"customer_email":"billing@northwind.example","amount_mentioned":89.00,"date_mentioned":"2026-02-14"}',
            ],
            [
                'role' => 'user',
                'content' => 'The reminder emails for invoice 2210 stopped sending last week',
            ],
            [
                'role' => 'assistant',
                'content' => '{"category":"bug","invoice_number":"2210","customer_email":null,"amount_mentioned":null,"date_mentioned":null}',
            ],
            [
                'role' => 'user',
                'content' => $message,
            ],
        ];
    }
}
