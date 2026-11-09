<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class GenerationCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        $reply = $context->roundTrip();

        if (! $reply->ok()) {
            return [CheckResult::fail("/v1/chat/completions round trip failed: {$reply->errorMessage()}")];
        }

        $usage = $reply->body['usage'] ?? null;

        if (! is_array($usage)) {
            return [CheckResult::fail('/v1/chat/completions answered without usage, so model_calls would not get exact counts: the backend returned no num_input_tokens and num_output_tokens outputs.')];
        }

        return [CheckResult::pass(sprintf(
            '/v1/chat/completions round trip %s · %d prompt tokens · %d completion tokens · no prompt_tokens_details, so cached tokens are estimated locally',
            number_format($reply->milliseconds / 1000, 1).' s',
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        ))];
    }
}
