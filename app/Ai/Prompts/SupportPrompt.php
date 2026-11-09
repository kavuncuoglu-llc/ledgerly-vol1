<?php

namespace App\Ai\Prompts;

use App\Ai\Context\AssembledPrompt;
use App\Ai\Context\ContextAssembler;
use App\Ai\Memory\MemoryStore;
use App\Models\User;

final class SupportPrompt
{
    public const VERSION = '2026-11-09.6';

    public static function system(?User $user = null): string
    {
        $base = self::baseSystem();

        $memories = self::memoriesBlock($user);

        if ($memories === '') {
            return $base;
        }

        return $base."\n\n".$memories;
    }

    public static function memoriesBlock(?User $user = null): string
    {
        if ($user === null) {
            return '';
        }

        return app(MemoryStore::class)->block($user);
    }

    public static function summaryNote(string $summary): string
    {
        return "Summary of the earlier part of this conversation (the turns it covers are no longer shown):\n".$summary;
    }

    public static function escapeHatch(): string
    {
        return 'I could not find that in the help center. Would you like me to open a support ticket?';
    }

    public static function unavailableMessage(): string
    {
        return 'Our assistant is briefly unavailable. You can search the help center in the meantime, or open a support ticket and we will reply as soon as it is back.';
    }

    private static function baseSystem(): string
    {
        return <<<'PROMPT'
You are the support assistant for Ledgerly, an invoicing product for small businesses. Answer questions about using Ledgerly; anything else is outside what you can help with.

Rules:
- Answer directly in plain language. No disclaimers about being an AI.
- At most four sentences, unless the question has steps: then a numbered list.
- Plain text only. No headers, bold, or bullet points.
- Do not restate the question or end with an offer of more help.
- State a policy, fee, deadline, or account detail only if it appears in the provided context or a tool result.
- Answer only from the provided context. Cite the label of each passage you rely on, in brackets, after the sentence it supports. Do not combine passages into a claim that neither makes alone.
- If the customer contradicts the context, say so politely and give the correct information.

If the answer is not in the provided context, or depends on the customer's account data, say so in one sentence and offer to open a support ticket. Do not guess. Answers that come from a tool result need no citation.

Tools: use lookup_invoice for any question about a specific invoice or payment; never guess invoice details. Use open_ticket when the customer reports a problem you cannot resolve from the context and tools, or asks you to log an issue; tell them the ticket number.
PROMPT;
    }

    public static function messages(string $question, ?User $user = null, ?string $summary = null, array $history = [], array $contextChunks = []): array
    {
        return self::assemble($question, $user, $summary, $history, $contextChunks)->messages;
    }

    public static function assemble(string $question, ?User $user = null, ?string $summary = null, array $history = [], array $contextChunks = []): AssembledPrompt
    {
        return app(ContextAssembler::class)
            ->system(self::baseSystem())
            ->memories(self::memoriesBlock($user))
            ->summary(self::summaryNoteOrNull($summary))
            ->history($history)
            ->context($contextChunks)
            ->question($question)
            ->build();
    }

    private static function summaryNoteOrNull(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        return self::summaryNote($summary);
    }
}
