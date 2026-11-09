<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class SlotsCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        $slots = $context->totalSlots();

        if ($slots === 0) {
            return [CheckResult::warn('/props reports no total_slots, so -np cannot be compared with the ai queue workers.')];
        }

        $workers = $context->workers->count();

        if ($slots === $workers) {
            return [CheckResult::pass("slots {$slots} = ai queue processes {$workers}")];
        }

        if ($slots < $workers) {
            return [CheckResult::warn("slots {$slots} < ai queue processes ({$workers}): the extra workers just wait for a free slot inside llama-server. Raise -np (LLAMA_PARALLEL) or lower the ai-interactive processes.")];
        }

        return [CheckResult::warn("slots {$slots} > ai queue processes ({$workers}): each slot reserves its own context, and nothing fills the extra ones. Lower -np (LLAMA_PARALLEL) or raise the ai-interactive processes.")];
    }
}
