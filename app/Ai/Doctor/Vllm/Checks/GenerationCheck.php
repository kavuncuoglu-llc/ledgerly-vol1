<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class GenerationCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $reply = $context->roundTrip();

        if (! $reply->ok()) {
            return [CheckResult::fail("/v1/chat/completions round trip failed: {$reply->errorMessage()}")];
        }

        $usage = $reply->body['usage'] ?? null;

        if (! is_array($usage)) {
            return [CheckResult::fail('/v1/chat/completions answered without usage, so model_calls would not get exact counts')];
        }

        $details = $usage['prompt_tokens_details'] ?? null;

        if (! is_array($details)) {
            return [CheckResult::warn("/v1/chat/completions round trip {$this->seconds($reply->milliseconds)} with exact counts, but no prompt_tokens_details: start vLLM with --enable-prompt-tokens-details, otherwise model_calls.cached_tokens is an estimate.")];
        }

        return [CheckResult::pass(sprintf(
            '/v1/chat/completions round trip %s · %d prompt tokens (%d cached) · %d completion tokens',
            $this->seconds($reply->milliseconds),
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($details['cached_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        ))];
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }
}
