<?php

namespace App\Ai\Prompts;

use App\Ai\Conversations\Exchange;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SummarizePrompt
{
    public const VERSION = '2026-11-09.1';

    private const TOOL_CONTENT_CHARACTERS = 1200;

    public static function system(): string
    {
        return <<<'PROMPT'
You maintain a running summary of a support conversation FOR AN AI ASSISTANT that will
continue the conversation. The turns you are given will be deleted; anything not in your
summary is lost. Update the existing summary with the new turns.

Keep, verbatim: invoice numbers, ticket numbers, dates, amounts, names, email addresses.
Record: the customer's problem, what has been established, what has been tried, what is
still open. Omit pleasantries. Terse list, not prose. At most 250 words.
PROMPT;
    }

    public static function messages(?string $existing, Collection $exchanges): array
    {
        $summary = $existing ?? '(none yet)';

        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => "Existing summary:\n{$summary}\n\nNew turns:\n".self::render($exchanges)."\n\nWrite the updated summary.",
            ],
        ];
    }

    private static function render(Collection $exchanges): string
    {
        return $exchanges
            ->flatMap(fn (Exchange $exchange): Collection => $exchange->messages())
            ->map(fn (Message $message): string => self::line($message))
            ->implode("\n");
    }

    private static function line(Message $message): string
    {
        if ($message->role === Message::ROLE_TOOL) {
            return 'tool result: '.Str::limit((string) $message->content, self::TOOL_CONTENT_CHARACTERS);
        }

        if ($message->tool_calls !== null) {
            return 'assistant called: '.self::callNames($message);
        }

        return "{$message->role}: {$message->content}";
    }

    private static function callNames(Message $message): string
    {
        return collect($message->tool_calls)
            ->map(fn (array $call): string => ($call['function']['name'] ?? '').'('.($call['function']['arguments'] ?? '').')')
            ->implode(', ');
    }
}
