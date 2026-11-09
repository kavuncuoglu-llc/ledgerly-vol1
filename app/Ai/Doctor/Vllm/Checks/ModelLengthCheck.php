<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class ModelLengthCheck implements VllmCheck
{
    private const WASTE_FACTOR = 2;

    public function run(VllmDoctorContext $context): array
    {
        if (! $context->modelsReply()->ok()) {
            return [];
        }

        $length = $context->maxModelLen();

        $window = $context->window;

        if ($length === 0) {
            return [CheckResult::warn('/v1/models reports no max_model_len, so --max-model-len cannot be compared with the budget window.')];
        }

        if ($length < $window) {
            return [CheckResult::fail("max-model-len {$length} < budget window {$window}: a prompt longer than {$length} tokens is refused with HTTP 400 (mapped to ContextTooLong). Raise --max-model-len (VLLM_MAX_MODEL_LEN).")];
        }

        if ($length >= $window * self::WASTE_FACTOR) {
            return [CheckResult::warn("max-model-len {$length} is ".$this->ratio($length, $window)." times the budget window {$window}: the KV cache has to hold one sequence of that length at start-up, and the maximum-concurrency figure falls with it. Lower --max-model-len (VLLM_MAX_MODEL_LEN) to the window.")];
        }

        return [CheckResult::pass("max-model-len {$length} >= budget window {$window} (an over-length request is a 400, mapped to ContextTooLong)")];
    }

    private function ratio(int $length, int $window): string
    {
        return rtrim(rtrim(number_format($length / $window, 1), '0'), '.');
    }
}
