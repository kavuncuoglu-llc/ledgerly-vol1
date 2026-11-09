<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class PrefixCacheCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        if (! $context->metricsReply()->ok()) {
            return [];
        }

        $metrics = $context->metrics();

        $state = $metrics->prefixCaching();

        if ($state === 'off') {
            return [CheckResult::warn('prefix caching is off: every request pays prefill for the whole system prompt, and Chapter 14\'s stable-first arrangement has nothing to hit. Start vLLM with --enable-prefix-caching.')];
        }

        if ($state === 'unknown') {
            return [CheckResult::warn('vllm:cache_config_info does not say whether prefix caching is on, so it cannot be checked from /metrics.')];
        }

        if ($metrics->sum('prefix_cache_queries') === 0.0) {
            return [CheckResult::pass('prefix caching on (no requests counted yet)')];
        }

        return [CheckResult::pass(sprintf('prefix caching on · %d%% of prompt tokens served from cached blocks', round($metrics->prefixCacheHitRatio() * 100)))];
    }
}
