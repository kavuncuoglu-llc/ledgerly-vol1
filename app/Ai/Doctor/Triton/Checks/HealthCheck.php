<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class HealthCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        $url = $context->client->baseUrl();

        $ready = $context->client->frontendReady();

        if (! $ready->reachable()) {
            return [CheckResult::fail("Triton OpenAI frontend at {$url} is not reachable. Is it running? The frontend only starts listening after tritonserver has loaded the models, so a start-up in progress looks like this too: read its log.")];
        }

        if ($ready->status === 400) {
            return [CheckResult::fail("Triton OpenAI frontend at {$url} answered /health/ready with 400: the attached inference engine is not ready for inference requests (a model is still loading, or failed to load).")];
        }

        if (! $ready->ok()) {
            return [CheckResult::fail("Triton OpenAI frontend at {$url} answered /health/ready with {$ready->errorMessage()}")];
        }

        $models = $context->modelsReply();

        if (in_array($models->status, [401, 403], true)) {
            return [CheckResult::fail("Triton at {$url} rejects this app's key (HTTP {$models->status} from /v1/models): TRITON_API_KEY does not match the value of --openai-restricted-api, or TRITON_AUTH_HEADER names another header.")];
        }

        return [
            CheckResult::pass("Triton OpenAI frontend at {$url} ready"),
            ...$this->kserve($context),
        ];
    }

    private function kserve(TritonDoctorContext $context): array
    {
        if (! $context->kserve->hasKserveUrl()) {
            return [CheckResult::warn('TRITON_KSERVE_URL is not set, so the KServe checks (server version, model repository index, model configuration) are skipped. Start the frontend with --enable-kserve-frontends and publish its HTTP port to see them.')];
        }

        $live = $context->kserve->serverLive();

        $ready = $context->kserve->serverReady();

        if (! $live->ok()) {
            return [CheckResult::fail("KServe /v2/health/live at {$context->kserve->baseUrl()} answered {$live->errorMessage()}")];
        }

        if (! $ready->ok()) {
            return [CheckResult::fail("KServe /v2/health/ready answered {$ready->errorMessage()}: at least one model is not READY.")];
        }

        return [CheckResult::pass($this->metadata($context))];
    }

    private function metadata(TritonDoctorContext $context): string
    {
        $metadata = $context->kserve->serverMetadata()->body;

        $name = (string) ($metadata['name'] ?? 'triton');

        $version = (string) ($metadata['version'] ?? '');

        if ($version === '') {
            return "KServe live and ready at {$context->kserve->baseUrl()}";
        }

        return "KServe live and ready · server {$name} {$version}";
    }
}
