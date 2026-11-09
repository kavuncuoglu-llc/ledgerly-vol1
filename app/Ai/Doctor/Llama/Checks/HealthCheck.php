<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class HealthCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        $url = $context->client->baseUrl();

        $health = $context->client->health();

        if (! $health->reachable()) {
            return [CheckResult::fail("llama-server at {$url} is not reachable. Is it running?")];
        }

        if ($health->status === 503) {
            return [CheckResult::fail("llama-server at {$url} is still loading the model (HTTP 503 from /health). Wait for it, or read its log if it never finishes.")];
        }

        if (! $health->ok()) {
            return [CheckResult::fail("llama-server at {$url} answered /health with {$health->errorMessage()}")];
        }

        $props = $context->props();

        if (in_array($props->status, [401, 403], true)) {
            return [CheckResult::fail("llama-server at {$url} rejects this app's api key (HTTP {$props->status} from /props): LLAMA_API_KEY does not match the server's --api-key.")];
        }

        if (! $props->ok()) {
            return [CheckResult::warn("llama-server at {$url} is healthy but /props answered {$props->errorMessage()}, so build, slots and context cannot be read.")];
        }

        return [CheckResult::pass($this->summary($url, $props->body))];
    }

    private function summary(string $url, array $props): string
    {
        $parts = array_filter([
            "llama-server at {$url} ok",
            $this->build($props),
            $this->model($props),
        ], fn (string $part): bool => $part !== '');

        return implode(' · ', $parts);
    }

    private function build(array $props): string
    {
        $build = (string) ($props['build_info'] ?? '');

        if ($build === '') {
            return '';
        }

        return "build {$build}";
    }

    private function model(array $props): string
    {
        $name = (string) ($props['model_alias'] ?? '');

        if ($name === '') {
            $name = basename((string) ($props['model_path'] ?? ''));
        }

        if ($name === '') {
            return '';
        }

        $ftype = (string) ($props['model_ftype'] ?? '');

        if ($ftype === '') {
            return "model {$name}";
        }

        return "model {$name} ({$ftype})";
    }
}
