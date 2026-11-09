<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class ServedModelCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        if (! $context->modelsReply()->ok()) {
            return [];
        }

        $served = array_map(fn (array $card): string => (string) ($card['id'] ?? ''), $context->servedModels());

        if (in_array($context->model(), $served, true)) {
            return [CheckResult::pass("provider model {$context->model()} is served")];
        }

        return [CheckResult::fail("provider model {$context->model()} is not served: /v1/models lists [".implode(', ', $served).']. Start vLLM with --served-model-name '.$context->model().' (VLLM_MODEL_ALIAS), or change VLLM_MODEL_ALIAS.')];
    }
}
