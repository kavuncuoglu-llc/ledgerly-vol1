<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class AuthCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        $reply = $context->client->propsWithoutKey();

        if ($reply->status === 401) {
            return [CheckResult::pass('api key enforced: /props without a key answers 401')];
        }

        if (! $reply->ok()) {
            return [CheckResult::warn("could not judge the api key: /props without a key answered {$reply->errorMessage()}")];
        }

        if ($this->isLoopback($context->client->host())) {
            return [CheckResult::warn('llama-server was started without --api-key: any process on this machine can use it. Set LLAMA_API_KEY on the server and in .env.')];
        }

        return [CheckResult::fail("llama-server on {$context->client->host()} answers without an api key: anyone who can reach it can run inference. Start it with --api-key (LLAMA_API_KEY) or keep it off the network.")];
    }

    private function isLoopback(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if ($host === '::1') {
            return true;
        }

        if ($host === '[::1]') {
            return true;
        }

        return str_starts_with($host, '127.');
    }
}
