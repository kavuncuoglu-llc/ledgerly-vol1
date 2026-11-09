<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class HealthCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $url = $context->client->baseUrl();

        $health = $context->client->health();

        if (! $health->reachable()) {
            return [CheckResult::fail("vLLM at {$url} is not reachable. Is it running? It only starts listening after the model is loaded and the KV cache is sized, so a start-up in progress looks like this too: read its log.")];
        }

        if ($health->status === 503) {
            return [CheckResult::fail("vLLM at {$url} answered /health with 503: its engine process is dead. Restart it and read the log for the cause.")];
        }

        if (! $health->ok()) {
            return [CheckResult::fail("vLLM at {$url} answered /health with {$health->errorMessage()}")];
        }

        $models = $context->modelsReply();

        if (in_array($models->status, [401, 403], true)) {
            return [CheckResult::fail("vLLM at {$url} rejects this app's api key (HTTP {$models->status} from /v1/models): VLLM_API_KEY does not match the server's --api-key.")];
        }

        if (! $models->ok()) {
            return [CheckResult::warn("vLLM at {$url} is healthy but /v1/models answered {$models->errorMessage()}, so the served model and its limits cannot be read.")];
        }

        return [CheckResult::pass($this->summary($url, $context))];
    }

    private function summary(string $url, VllmDoctorContext $context): string
    {
        $parts = array_filter([
            "vLLM at {$url} ok",
            $this->version($context),
            $this->model($context),
        ], fn (string $part): bool => $part !== '');

        return implode(' · ', $parts);
    }

    private function version(VllmDoctorContext $context): string
    {
        $reply = $context->client->version();

        $version = $reply->body['version'] ?? '';

        if (! is_string($version)) {
            return '';
        }

        if ($version === '') {
            return '';
        }

        return "version {$version}";
    }

    private function model(VllmDoctorContext $context): string
    {
        $model = $context->model();

        $quantization = (string) config('ai.vllm.quantization');

        if ($quantization === '') {
            return "model {$model}";
        }

        return "model {$model} ({$quantization})";
    }
}
