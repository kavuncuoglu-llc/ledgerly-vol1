<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\LoopbackHost;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class AuthCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $reply = $context->client->modelsWithoutKey();

        if ($reply->status === 401) {
            return [CheckResult::pass('api key enforced: /v1/models without a key answers 401 (--api-key guards /v1 only; /health and /metrics stay open, so keep the port off the network)')];
        }

        if (! $reply->ok()) {
            return [CheckResult::warn("could not judge the api key: /v1/models without a key answered {$reply->errorMessage()}")];
        }

        if (LoopbackHost::is($context->client->host())) {
            return [CheckResult::warn('vLLM was started without --api-key: any process on this machine can use it. Set VLLM_API_KEY on the server and in .env.')];
        }

        return [CheckResult::fail("vLLM on {$context->client->host()} answers without an api key: anyone who can reach it can run inference. Start it with --api-key (VLLM_API_KEY) or keep it off the network.")];
    }
}
