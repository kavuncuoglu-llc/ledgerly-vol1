<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class TokenizeCheck implements LlamaCheck
{
    private const SAMPLE = 'Invoice #4471 is overdue by 30 days.';

    private const ACTIVE_COUNTER = 'llama-server';

    public function run(LlamaDoctorContext $context): array
    {
        $reply = $context->client->tokenize(self::SAMPLE);

        $tokens = $reply->body['tokens'] ?? null;

        if (! $reply->ok()) {
            return [CheckResult::fail("/tokenize failed: {$reply->errorMessage()}")];
        }

        if (! is_array($tokens)) {
            return [CheckResult::fail('/tokenize answered without a tokens array, so budgets cannot be counted exactly.')];
        }

        $count = count($tokens);

        $counter = (string) config('ai.token_counter');

        if ($counter === self::ACTIVE_COUNTER) {
            return [CheckResult::pass("/tokenize exact counter active ({$count} tokens for the sample)")];
        }

        return [CheckResult::warn("/tokenize works ({$count} tokens for the sample) but the app counts with [{$counter}]: set AI_TOKEN_COUNTER=llama-server so budgets use the model's own tokenizer.")];
    }
}
