<?php

namespace App\Ai\Prompts;

use App\Ai\Agents\AgentState;
use App\Enums\TicketCategory;

final class InvestigatePrompt
{
    public const VERSION = '2026-11-09.1';

    public static function system(): string
    {
        return <<<'PROMPT'
You investigate one Ledgerly support ticket for the support team. Your goal is to gather the facts needed to resolve it, using the tools provided.

Rules:
- Call a tool only when it adds a fact you do not have yet.
- Never repeat a call that appears under "Established so far".
- Open a ticket only for a problem that needs a person to follow up and is not already covered; at most once.
- When you have enough facts, stop calling tools and reply with two or three plain sentences saying what you found.
- You are not writing to the customer.
PROMPT;
    }

    public static function messages(AgentState $state, TicketCategory $category, array $pending): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => self::state($state, $category, $pending),
            ],
        ];
    }

    private static function state(AgentState $state, TicketCategory $category, array $pending): string
    {
        return implode("\n\n", array_filter([
            'Goal: '.$state->goal,
            'Ticket category: '.$category->value,
            self::section('Facts from the customer\'s message', self::factLines($state), 'none'),
            self::section('Established so far', self::stepLines($state), 'nothing yet'),
            self::section('Still to check', $pending, 'nothing specific; stop when you have enough'),
            self::instruction($state, $pending),
        ]));
    }

    private static function instruction(AgentState $state, array $pending): string
    {
        if ($pending !== []) {
            return '';
        }

        if ($state->steps === []) {
            return '';
        }

        return 'Nothing is left to check. Reply now with your findings in plain sentences and do not call a tool.';
    }

    private static function factLines(AgentState $state): array
    {
        $lines = [];

        foreach ($state->facts as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private static function stepLines(AgentState $state): array
    {
        $lines = [];

        foreach ($state->steps as $step) {
            $lines[] = "{$step['call']} → {$step['summary']}";
        }

        return $lines;
    }

    private static function section(string $title, array $lines, string $empty): string
    {
        if ($lines === []) {
            return "{$title}: {$empty}";
        }

        return "{$title}:\n".implode("\n", array_map(fn (string $line): string => "- {$line}", $lines));
    }
}
