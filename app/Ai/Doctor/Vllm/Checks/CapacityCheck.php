<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class CapacityCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $reply = $context->metricsReply();

        if (! $reply->ok()) {
            return [];
        }

        $metrics = $context->metrics();

        $tokens = $metrics->kvCacheTokens();

        if ($tokens === 0) {
            return [CheckResult::warn('vllm:cache_config_info carries neither kv_cache_size_tokens nor num_gpu_blocks, so the KV cache capacity cannot be read from /metrics. Read the "GPU KV cache size" line in the server log.')];
        }

        $concurrency = $metrics->kvCacheMaxConcurrency();

        $length = $context->maxModelLen();

        if ($concurrency === 0.0) {
            $concurrency = $this->derivedConcurrency($tokens, $length);
        }

        if ($concurrency === 0.0) {
            return [CheckResult::pass('KV cache '.number_format($tokens).' tokens')];
        }

        if ($concurrency < 1.0) {
            return [CheckResult::fail('KV cache '.number_format($tokens)." tokens holds less than one full-window sequence of {$length} tokens. Raise --gpu-memory-utilization, lower --max-model-len, or use a smaller or more quantized model.")];
        }

        return [CheckResult::pass(sprintf('KV cache %s tokens · max concurrency ~%d at the full %s-token window', number_format($tokens), (int) round($concurrency), number_format($length)))];
    }

    private function derivedConcurrency(int $tokens, int $length): float
    {
        if ($length === 0) {
            return 0.0;
        }

        return $tokens / $length;
    }
}
