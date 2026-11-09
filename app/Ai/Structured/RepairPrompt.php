<?php

namespace App\Ai\Structured;

use App\Ai\Retrieval\CitationReport;
use Illuminate\Support\MessageBag;

final class RepairPrompt
{
    public static function for(MessageBag $errors): string
    {
        $encodedErrors = $errors->toJson(JSON_PRETTY_PRINT);

        return <<<TXT
The object you returned has validation errors:

{$encodedErrors}

Return a corrected object matching the same schema. Change only what is needed to
fix the errors. If a value cannot be determined from the source, use null.
TXT;
    }

    public static function citations(CitationReport $report): array
    {
        $problems = implode("\n", array_map(fn (string $error): string => "- {$error}", $report->errors));

        return [
            'role' => 'user',
            'content' => "Your answer failed the citation check:\n{$problems}\n\nRewrite the answer using only the provided passages. Cite the label of each passage you rely on, in brackets, after the sentence it supports. If the passages do not contain the answer, say so in one sentence and offer to open a support ticket.",
        ];
    }
}
