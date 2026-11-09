<?php

namespace App\Ai\Prompts;

use App\Ai\Context\ContextBlock;
use App\Enums\TicketCategory;
use App\Models\Ticket;

final class DraftReplyPrompt
{
    public const VERSION = '2026-11-09.1';

    public static function system(): string
    {
        return <<<'PROMPT'
You draft the first reply to a Ledgerly support ticket. A support person will read, edit and send it; the customer sees nothing until then.

Rules:
- At most five sentences, plain text, friendly and direct.
- State an amount, date, fee or status only if it appears in the investigation facts or the help passages.
- Cite the label of each help passage you rely on, in brackets, after the sentence it supports.
- Never promise a refund, credit or deadline.
- If the facts do not settle the question, say a team member will follow up.
PROMPT;
    }

    public static function messages(Ticket $ticket, TicketCategory $category, array $findings, array $chunks): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => self::ticketMessage($ticket, $category, $findings, $chunks),
            ],
        ];
    }

    private static function ticketMessage(Ticket $ticket, TicketCategory $category, array $findings, array $chunks): string
    {
        $parts = [
            "Ticket category: {$category->value}",
            "Subject: {$ticket->subject}",
            "Message:\n{$ticket->body}",
        ];

        if ($findings !== []) {
            $parts[] = "Investigation facts (JSON):\n".json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($chunks !== []) {
            $parts[] = ContextBlock::render($chunks);
        }

        $parts[] = 'Write the draft reply.';

        return implode("\n\n", $parts);
    }
}
