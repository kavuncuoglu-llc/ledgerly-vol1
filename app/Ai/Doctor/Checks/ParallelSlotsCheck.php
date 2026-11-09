<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class ParallelSlotsCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $workers = $context->workers->count();

        $declared = (int) config('ai.ollama.num_parallel');

        if ($declared === 0) {
            return [CheckResult::warn("OLLAMA_NUM_PARALLEL is not declared for this app, and the server does not report it, so it cannot be compared with the {$workers} ai queue workers. Set OLLAMA_NUM_PARALLEL in .env to the value the server runs with (Ollama's own default is 1, auto up to 4).")];
        }

        if ($declared === $workers) {
            return [CheckResult::pass("OLLAMA_NUM_PARALLEL={$declared} (declared) matches ai queue processes ({$workers})")];
        }

        if ($declared < $workers) {
            return [CheckResult::warn("OLLAMA_NUM_PARALLEL={$declared} (declared) < ai queue processes ({$workers}): the extra workers just queue inside Ollama.")];
        }

        return [CheckResult::warn("OLLAMA_NUM_PARALLEL={$declared} (declared) > ai queue processes ({$workers}): each slot holds a KV cache that nothing fills.")];
    }
}
