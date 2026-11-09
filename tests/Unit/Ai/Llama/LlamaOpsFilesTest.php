<?php

namespace Tests\Unit\Ai\Llama;

use Tests\TestCase;

class LlamaOpsFilesTest extends TestCase
{
    public function test_the_gpu_service_is_opt_in_loopback_only_and_reserves_the_gpu(): void
    {
        $service = $this->composeService('llama');

        $this->assertStringContainsString('profiles: ["llama-gpu"]', $service);
        $this->assertStringContainsString('"127.0.0.1:${LLAMA_PORT:-8080}:8080"', $service);
        $this->assertDoesNotMatchRegularExpression('/^\s+- "?0\.0\.0\.0:/m', $service);
        $this->assertStringContainsString('driver: nvidia', $service);
        $this->assertStringContainsString('dockerfile: Dockerfile.cuda', $service);
    }

    public function test_the_cpu_service_is_opt_in_loopback_only_and_offloads_nothing(): void
    {
        $service = $this->composeService('llama-cpu');

        $this->assertStringContainsString('profiles: ["llama-cpu"]', $service);
        $this->assertStringContainsString('"127.0.0.1:${LLAMA_PORT:-8080}:8080"', $service);
        $this->assertStringNotContainsString('driver: nvidia', $service);
        $this->assertStringContainsString('dockerfile: Dockerfile.cpu', $service);
        $this->assertMatchesRegularExpression('/- -ngl\n\s+- "0"/', $service);
    }

    public function test_both_services_require_the_api_key_and_switch_metrics_on(): void
    {
        foreach (['llama', 'llama-cpu'] as $name) {
            $service = $this->composeService($name);

            $this->assertStringContainsString('- --api-key', $service, $name);
            $this->assertStringContainsString('${LLAMA_API_KEY:-', $service, $name);
            $this->assertStringContainsString('- --metrics', $service, $name);
            $this->assertStringContainsString('- --alias', $service, $name);
            $this->assertStringContainsString('./models:/models:ro', $service, $name);
        }
    }

    public function test_the_gpu_slots_default_gives_each_slot_the_budget_window(): void
    {
        $service = $this->composeService('llama');

        preg_match('/\$\{LLAMA_CTX:-(\d+)\}/', $service, $context);
        preg_match('/\$\{LLAMA_PARALLEL:-(\d+)\}/', $service, $slots);

        $this->assertSame((int) config('ai.budget.window'), intdiv((int) $context[1], (int) $slots[1]));
    }

    public function test_the_cpu_slots_default_gives_each_slot_the_budget_window(): void
    {
        $service = $this->composeService('llama-cpu');

        preg_match('/\$\{LLAMA_CPU_CTX:-(\d+)\}/', $service, $context);
        preg_match('/\$\{LLAMA_CPU_PARALLEL:-(\d+)\}/', $service, $slots);

        $this->assertSame((int) config('ai.budget.window'), intdiv((int) $context[1], (int) $slots[1]));
    }

    public function test_the_gpu_service_turns_the_prefix_cache_and_flash_attention_on(): void
    {
        $service = $this->composeService('llama');

        $this->assertMatchesRegularExpression('/- -fa\n\s+- "on"/', $service);
        $this->assertStringContainsString('${LLAMA_CACHE_REUSE:-256}', $service);
    }

    public function test_both_dockerfiles_pin_the_same_release_as_compose(): void
    {
        preg_match('/\$\{LLAMA_TAG:-(b\d+)\}/', $this->composeService('llama'), $compose);

        foreach (['Dockerfile.cuda', 'Dockerfile.cpu'] as $file) {
            $dockerfile = (string) file_get_contents(base_path("ops/llama/{$file}"));

            $this->assertStringContainsString("ARG LLAMA_TAG={$compose[1]}", $dockerfile, $file);
            $this->assertMatchesRegularExpression('#^FROM ghcr\.io/ggml-org/llama\.cpp:server(-cuda)?-\$\{LLAMA_TAG\}$#m', $dockerfile, $file);
        }
    }

    public function test_the_speculative_overlay_only_adds_the_draft_model_to_the_gpu_service(): void
    {
        $overlay = (string) file_get_contents(base_path('ops/llama/compose.speculative.yml'));

        $this->assertStringContainsString('LLAMA_ARG_SPEC_DRAFT_MODEL', $overlay);
        $this->assertStringContainsString('LLAMA_ARG_SPEC_DRAFT_N_MAX', $overlay);
        $this->assertStringNotContainsString('llama-cpu', $overlay);
    }

    public function test_the_pull_script_fails_on_a_checksum_mismatch_and_says_when_it_was_not_verified(): void
    {
        $script = (string) file_get_contents(base_path('ops/llama/pull.sh'));

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('LLAMA_MODEL_SHA256', $script);
        $this->assertStringContainsString('sha256 mismatch', $script);
        $this->assertStringContainsString('this download was not verified', $script);
        $this->assertTrue(is_executable(base_path('ops/llama/pull.sh')));
    }

    public function test_downloaded_models_are_not_committed(): void
    {
        $this->assertStringContainsString('/models/*.gguf', (string) file_get_contents(base_path('.gitignore')));
    }

    private function composeService(string $name): string
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        preg_match('/^  '.preg_quote($name, '/').':\n(?<body>(?:    .*\n|\n)+)/m', $compose, $matches);

        return $matches['body'] ?? '';
    }
}
