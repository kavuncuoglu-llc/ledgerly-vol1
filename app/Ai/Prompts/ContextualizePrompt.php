<?php

namespace App\Ai\Prompts;

final class ContextualizePrompt
{
    public const VERSION = '2026-11-10.1';

    public static function document(string $articleBody): string
    {
        return "<document>\n{$articleBody}\n</document>";
    }

    public static function chunkRequest(string $chunkText): string
    {
        return "<chunk>\n{$chunkText}\n</chunk>\n\nIn one or two sentences, say where this chunk sits in the document above "
            .'(which section, plan, or feature), to help search find it. Reply with that context only.';
    }
}
