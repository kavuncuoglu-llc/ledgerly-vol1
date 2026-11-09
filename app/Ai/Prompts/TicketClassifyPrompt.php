<?php

namespace App\Ai\Prompts;

final class TicketClassifyPrompt
{
    public const VERSION = '2026-11-01.1';

    public const LABELS = [
        'billing',
        'howto',
        'bug',
        'account',
        'other',
    ];

    public static function system(): string
    {
        return <<<'PROMPT'
You classify Ledgerly support messages into exactly one category:
billing - charges, fees, payments, refunds, invoice status
howto - how to use a feature that works as designed
bug - something in the product is broken or behaving wrongly
account - login, password, two-factor, team members, permissions
other - anything that does not fit, including off-topic requests
Reply with the category word only, lowercase, nothing else.
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
                'content' => 'How do I set up recurring invoices?',
            ],
            [
                'role' => 'assistant',
                'content' => 'howto',
            ],
            [
                'role' => 'user',
                'content' => 'Why was I charged a late fee on invoice 4471?',
            ],
            [
                'role' => 'assistant',
                'content' => 'billing',
            ],
            [
                'role' => 'user',
                'content' => 'The PDF export button does nothing when I click it',
            ],
            [
                'role' => 'assistant',
                'content' => 'bug',
            ],
            [
                'role' => 'user',
                'content' => 'Can you recommend an accountant?',
            ],
            [
                'role' => 'assistant',
                'content' => 'other',
            ],
            [
                'role' => 'user',
                'content' => $message,
            ],
        ];
    }
}
