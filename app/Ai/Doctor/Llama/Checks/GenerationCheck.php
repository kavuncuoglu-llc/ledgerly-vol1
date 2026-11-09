<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class GenerationCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        $reply = $context->roundTrip();

        if (! $reply->ok()) {
            return [CheckResult::fail("/v1/chat/completions round trip failed: {$reply->errorMessage()}")];
        }

        $usage = $reply->body['usage'] ?? null;

        if (! is_array($usage)) {
            return [CheckResult::fail('/v1/chat/completions answered without usage, so model_calls would not get exact counts')];
        }

        $timings = $reply->body['timings'] ?? null;

        if (! is_array($timings)) {
            return [CheckResult::warn("/v1/chat/completions round trip {$this->seconds($reply->milliseconds)} with exact counts, but no timings object: ttft_ms will be the client-measured time.")];
        }

        return [CheckResult::pass(sprintf(
            '/v1/chat/completions round trip %s · %d prompt tokens (%d reused from the cache) at %s tok/s · decode %s tok/s',
            $this->seconds($reply->milliseconds),
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($timings['cache_n'] ?? 0),
            $this->rate($timings['prompt_per_second'] ?? null),
            $this->rate($timings['predicted_per_second'] ?? null),
        ))];
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }

    private function rate(mixed $perSecond): string
    {
        if (! is_numeric($perSecond)) {
            return 'unknown';
        }

        return number_format((float) $perSecond, 1);
    }
}
