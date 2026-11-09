<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class SlotContextCheck implements LlamaCheck
{
    private const WASTE_FACTOR = 2;

    public function run(LlamaDoctorContext $context): array
    {
        $perSlot = $context->slotContext();

        $window = $context->window;

        if ($perSlot === 0) {
            return [CheckResult::warn('/props reports no default_generation_settings.n_ctx, so the context per slot cannot be compared with the budget window.')];
        }

        if ($perSlot < $window) {
            return [CheckResult::fail("context per slot {$perSlot} < budget window {$window}: a prompt longer than {$perSlot} tokens is refused by llama-server (HTTP 400, exceed_context_size_error). Raise -c to slots x window (LLAMA_CTX).")];
        }

        if ($perSlot >= $window * self::WASTE_FACTOR) {
            return [CheckResult::warn("context per slot {$perSlot} is {$this->ratio($perSlot, $window)} times the budget window {$window}: every slot's KV cache is allocated for all of it. Lower -c (LLAMA_CTX) to slots x window.")];
        }

        return [CheckResult::pass("context per slot {$perSlot} >= budget window {$window}")];
    }

    private function ratio(int $perSlot, int $window): string
    {
        return rtrim(rtrim(number_format($perSlot / $window, 1), '0'), '.');
    }
}
