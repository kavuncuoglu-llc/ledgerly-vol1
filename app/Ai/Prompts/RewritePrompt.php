<?php

namespace App\Ai\Prompts;

use App\Ai\Retrieval\RewriteMode;

final class RewritePrompt
{
    public const VERSION = '2026-11-10.1';

    public static function system(RewriteMode $mode): string
    {
        return match ($mode) {
            RewriteMode::Rewrite => <<<'PROMPT'
Restate the customer's question as a short phrase in the style of a help-center heading or first sentence,
in documentation register. Keep every identifier (numbers, product names) verbatim. Output the phrase only.
PROMPT,
            RewriteMode::Expand => <<<'PROMPT'
Write three alternative phrasings of the customer's question, in the vocabulary a help-center article would use.
Keep every identifier (numbers, product names) verbatim. Output a JSON array of three strings and nothing else.
PROMPT,
            RewriteMode::Hyde => <<<'PROMPT'
Write one short paragraph that a help-center article might contain as the answer to the customer's question.
Plausible wording matters more than correct details. Keep every identifier (numbers, product names) verbatim.
Output the paragraph only.
PROMPT,
            RewriteMode::Off => '',
        };
    }

    public static function messages(RewriteMode $mode, string $question): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system($mode),
            ],
            [
                'role' => 'user',
                'content' => $question,
            ],
        ];
    }
}
