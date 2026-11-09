<?php

namespace App\Ai\Structured;

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
}
