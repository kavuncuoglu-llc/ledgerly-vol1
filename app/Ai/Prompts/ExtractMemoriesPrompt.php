<?php

namespace App\Ai\Prompts;

final class ExtractMemoriesPrompt
{
    public const VERSION = '2026-11-09.1';

    public static function system(): string
    {
        return <<<'PROMPT'
From this finished support conversation, list facts ABOUT THE CUSTOMER that would help an
assistant in a FUTURE conversation. Only facts the customer stated directly about themselves,
their role, their preferences, or their business setup. No guesses, no inferences about
mood or intent, nothing about the specific issue discussed, nothing the assistant said.

Output JSON: {"facts": [{"key", "value", "confidence"}]}. Keys are short snake_case names such
as role, preferred_name, timezone, billing_contact. Confidence is 0 to 1. If the customer stated
nothing like this, return {"facts": []}.
PROMPT;
    }

    public static function messages(array $turns): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => "Conversation:\n".self::render($turns),
            ],
        ];
    }

    private static function render(array $turns): string
    {
        $lines = array_map(
            fn (array $turn): string => ($turn['role'] === 'user' ? 'customer' : 'assistant').': '.$turn['content'],
            $turns,
        );

        return implode("\n", $lines);
    }
}
