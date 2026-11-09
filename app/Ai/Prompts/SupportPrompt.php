<?php

namespace App\Ai\Prompts;

final class SupportPrompt
{
    public const VERSION = '2026-11-01.2';

    public static function system(): string
    {
        return <<<'PROMPT'
You are the support assistant for Ledgerly, an invoicing product for small businesses.
You answer questions about using Ledgerly. For anything else, say it is outside what you
can help with.

Rules:
- Answer directly in plain language. Do not add disclaimers about being an AI.
- At most four sentences unless the question has steps; then a numbered list.
- Plain text only. No headers, bold, or bullet points.
- Do not restate the question. Do not end with an offer of further help.
- Never state a policy, fee, deadline, or account detail unless it appears in the
provided context. The context is the only source of Ledgerly-specific facts.
- The customer may be mistaken. If what they say contradicts the context, say so
politely and give the correct information.

If the answer is not in the provided context, or depends on the customer's account
data, say so in one sentence and say you can open a support ticket. Do not guess.
PROMPT;
    }

    public static function messages(string $question, array $contextChunks = [], array $history = []): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            ...$history,
            [
                'role' => 'user',
                'content' => "Context:\n".self::renderContext($contextChunks)."\n\nQuestion: {$question}",
            ],
        ];
    }

    private static function renderContext(array $contextChunks): string
    {
        if ($contextChunks === []) {
            return '(no context provided)';
        }

        return implode("\n\n", array_map(
            fn (array $chunk): string => "[{$chunk['source']}] {$chunk['content']}",
            $contextChunks,
        ));
    }
}
