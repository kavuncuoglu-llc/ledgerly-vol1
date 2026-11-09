<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class ServedModelCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        if (! $context->modelsReply()->ok()) {
            return [];
        }

        $served = $context->servedModelIds();

        if (in_array($context->model(), $served, true)) {
            return [CheckResult::pass("provider model {$context->model()} is served by the OpenAI frontend")];
        }

        return [CheckResult::fail("provider model {$context->model()} is not served: /v1/models lists [".implode(', ', $served).']. The frontend lists only models with the vllm or tensorrtllm backend; change TRITON_MODEL_ALIAS to the directory name in the model repository.')];
    }
}
