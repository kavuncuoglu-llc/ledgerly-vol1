<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class AuthCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        $reply = $context->client->modelsWithoutKey();

        if (! $reply->reachable()) {
            return [];
        }

        if ($reply->ok()) {
            return [CheckResult::warn("/v1/models answered without the {$this->header($context)} header: the OpenAI frontend has no authentication unless it is started with --openai-restricted-api 'inference,model-repository {$this->header($context)} <value>'. Anything that can reach the port can run inferences and load or unload models.")];
        }

        if ($reply->status !== 401) {
            return [CheckResult::warn("/v1/models without the key answered {$reply->errorMessage()}, not 401")];
        }

        return [CheckResult::pass("the {$this->header($context)} header is enforced on /v1 (401 without it); /health/ready and /metrics stay open unless their APIs are restricted too")];
    }

    private function header(TritonDoctorContext $context): string
    {
        return (string) ($context->providerConfig['auth_header'] ?? 'x-api-key');
    }
}
