<?php

namespace App\Ai\Prompts;

use App\Ai\Memory\MemoryStore;
use App\Models\User;

final class SupportPrompt
{
    public const VERSION = '2026-11-09.4';

    public static function system(?User $user = null): string
    {
        $base = self::baseSystem();

        if ($user === null) {
            return $base;
        }

        $memories = app(MemoryStore::class)->block($user);

        if ($memories === '') {
            return $base;
        }

        return $base."\n\n".$memories;
    }

    public static function summaryNote(string $summary): string
    {
        return "Summary of the earlier part of this conversation (the turns it covers are no longer shown):\n".$summary;
    }

    private static function baseSystem(): string
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
provided context or in a tool result. Those are the only sources of Ledgerly-specific facts.
- The customer may be mistaken. If what they say contradicts the context, say so
politely and give the correct information.

If the answer is not in the provided context, or depends on the customer's account
data, say so in one sentence and say you can open a support ticket. Do not guess.

You have tools. Use lookup_invoice whenever the customer asks about a specific invoice or
payment; never guess at invoice details. Use open_ticket when the customer reports a problem
you cannot resolve from the context and tools, or asks you to log an issue. Tell the customer
the ticket number.
PROMPT;
    }

    public static function messages(string $question, ?User $user = null, ?string $summary = null, array $history = [], array $contextChunks = []): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::system($user),
            ],
            ...self::summaryMessages($summary),
            ...$history,
            [
                'role' => 'user',
                'content' => "Context:\n".self::renderContext($contextChunks)."\n\nQuestion: {$question}",
            ],
        ];
    }

    private static function summaryMessages(?string $summary): array
    {
        if ($summary === null) {
            return [];
        }

        return [
            [
                'role' => 'system',
                'content' => self::summaryNote($summary),
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
