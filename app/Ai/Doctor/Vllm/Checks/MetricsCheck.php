<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;
use App\Ai\Vllm\VllmMetrics;

final class MetricsCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $reply = $context->metricsReply();

        if (! $reply->ok()) {
            return [CheckResult::warn("/metrics answered {$reply->errorMessage()}: there is no running, waiting, KV cache or latency data to scrape.")];
        }

        $metrics = $context->metrics();

        if ($metrics->isEmpty()) {
            return [CheckResult::warn('/metrics answered 200 but no vllm: series were found in the body.')];
        }

        if ($metrics->sum('num_preemptions') > 0.0) {
            return [CheckResult::warn(sprintf('/metrics reachable · %d preemptions since start: the KV cache filled and sequences were recomputed. Lower --max-num-seqs or --max-model-len, or raise --gpu-memory-utilization.', $metrics->sum('num_preemptions')))];
        }

        return [CheckResult::pass($this->summary($metrics))];
    }

    private function summary(VllmMetrics $metrics): string
    {
        $parts = array_filter([
            '/metrics reachable',
            $this->requests($metrics),
            $this->cacheUsage($metrics),
            $this->adapters($metrics),
        ], fn (string $part): bool => $part !== '');

        return implode(' · ', $parts);
    }

    private function requests(VllmMetrics $metrics): string
    {
        if (! $metrics->has('num_requests_running')) {
            return '';
        }

        return sprintf('%d running, %d waiting', $metrics->sum('num_requests_running'), $metrics->sum('num_requests_waiting'));
    }

    private function cacheUsage(VllmMetrics $metrics): string
    {
        if (! $metrics->has('kv_cache_usage_perc')) {
            return '';
        }

        return sprintf('KV cache %d%% used', round($metrics->sum('kv_cache_usage_perc') * 100));
    }

    private function adapters(VllmMetrics $metrics): string
    {
        if (! $metrics->has('lora_requests_info')) {
            return '';
        }

        $slots = $metrics->labels('lora_requests_info')['max_lora'] ?? '';

        if ($slots === '') {
            return 'LoRA serving on';
        }

        return "LoRA serving on (max {$slots} adapters per batch)";
    }
}
