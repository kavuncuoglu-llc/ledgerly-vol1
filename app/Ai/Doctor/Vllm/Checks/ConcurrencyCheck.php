<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class ConcurrencyCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $workers = $context->workers->count();

        $declared = config('ai.vllm.max_num_seqs');

        if ($declared !== null) {
            if ($workers > (int) $declared) {
                return [CheckResult::warn("ai queue processes ({$workers}) > --max-num-seqs ({$declared}): the extra workers wait inside vLLM. Raise VLLM_MAX_NUM_SEQS or lower the ai-interactive processes (AI_CONCURRENCY).")];
            }
        }

        $fits = $this->fullWindowSequences($context);

        if ($fits > 0) {
            if ($workers > $fits) {
                return [CheckResult::warn("ai queue processes ({$workers}) > the {$fits} full-window sequences the KV cache holds: replies are mostly shorter, so they usually fit, but a burst of long conversations queues or preempts (watch vllm:num_preemptions). Lower AI_CONCURRENCY to the knee ai:load finds.")];
            }
        }

        return [CheckResult::pass($this->passMessage($workers, $declared))];
    }

    private function passMessage(int $workers, mixed $declared): string
    {
        if ($declared === null) {
            return "ai queue processes {$workers} (set VLLM_MAX_NUM_SEQS to the server's --max-num-seqs to compare it with them)";
        }

        return "ai queue processes {$workers} <= --max-num-seqs {$declared}";
    }

    private function fullWindowSequences(VllmDoctorContext $context): int
    {
        if (! $context->metricsReply()->ok()) {
            return 0;
        }

        $metrics = $context->metrics();

        $concurrency = $metrics->kvCacheMaxConcurrency();

        if ($concurrency === 0.0) {
            $length = $context->maxModelLen();

            if ($length === 0) {
                return 0;
            }

            $concurrency = $metrics->kvCacheTokens() / $length;
        }

        return (int) floor($concurrency);
    }
}
