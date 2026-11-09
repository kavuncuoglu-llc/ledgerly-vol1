<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;
use App\Ai\Llama\LlamaMetrics;

final class MetricsCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        $reply = $context->metricsReply();

        if ($reply->status === 501) {
            return [CheckResult::warn('/metrics is off: llama-server was started without --metrics, so there is no throughput, slot or prefix-cache data to scrape.')];
        }

        if (! $reply->ok()) {
            return [CheckResult::warn("/metrics answered {$reply->errorMessage()}")];
        }

        $metrics = $context->metrics();

        if ($metrics->isEmpty()) {
            return [CheckResult::warn('/metrics answered 200 but no llamacpp: series were found in the body.')];
        }

        return [CheckResult::pass($this->summary($metrics, $context))];
    }

    private function summary(LlamaMetrics $metrics, LlamaDoctorContext $context): string
    {
        $parts = array_filter([
            '/metrics reachable',
            $this->prefixCache($metrics),
            $this->highWatermark($metrics, $context),
            $this->speculation($metrics),
        ], fn (string $part): bool => $part !== '');

        return implode(' · ', $parts);
    }

    private function prefixCache(LlamaMetrics $metrics): string
    {
        if (! $metrics->has('prompt_tokens_cached_total')) {
            return '';
        }

        return sprintf('prefix cache reused %d%% of prompt tokens', round($metrics->promptCacheHitRatio() * 100));
    }

    private function highWatermark(LlamaMetrics $metrics, LlamaDoctorContext $context): string
    {
        if (! $metrics->has('n_tokens_max')) {
            return '';
        }

        $longest = (int) $metrics->value('n_tokens_max');

        $perSlot = $context->slotContext();

        if ($perSlot === 0) {
            return "longest sequence {$longest} tokens";
        }

        return "longest sequence {$longest} of {$perSlot} tokens";
    }

    private function speculation(LlamaMetrics $metrics): string
    {
        if ($metrics->value('spec_decode_num_draft_tokens_total') === 0.0) {
            return '';
        }

        return sprintf('speculative decoding accepted %d%% of drafted tokens', round($metrics->draftAcceptanceRatio() * 100));
    }
}
