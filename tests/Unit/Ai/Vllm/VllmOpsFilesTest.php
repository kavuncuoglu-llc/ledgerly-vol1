<?php

namespace Tests\Unit\Ai\Vllm;

use Tests\TestCase;

class VllmOpsFilesTest extends TestCase
{
    public function test_the_service_is_opt_in_loopback_only_and_reserves_the_gpu(): void
    {
        $service = $this->composeService('vllm');

        $this->assertStringContainsString('profiles: ["vllm"]', $service);
        $this->assertStringContainsString('"127.0.0.1:${VLLM_PORT:-8001}:8000"', $service);
        $this->assertDoesNotMatchRegularExpression('/^\s+- "?0\.0\.0\.0:/m', $service);
        $this->assertStringContainsString('driver: nvidia', $service);
        $this->assertStringContainsString('ipc: host', $service);
    }

    public function test_the_host_port_does_not_collide_with_artisan_serve_or_llama_server(): void
    {
        $service = $this->composeService('vllm');

        preg_match('/\$\{VLLM_PORT:-(\d+)\}/', $service, $port);

        $this->assertNotContains((int) $port[1], [8000, 8080, 11434]);
    }

    public function test_the_image_is_pinned_to_a_release_and_the_model_revision_is_a_flag(): void
    {
        $service = $this->composeService('vllm');

        $this->assertMatchesRegularExpression('#image: vllm/vllm-openai:\$\{VLLM_TAG:-v\d+\.\d+\.\d+\}#', $service);
        $this->assertMatchesRegularExpression('/- --revision\n\s+- \$\{VLLM_REVISION:-/', $service);
    }

    public function test_the_flags_the_chapter_names_are_all_passed(): void
    {
        $service = $this->composeService('vllm');

        foreach (['--max-model-len', '--gpu-memory-utilization', '--max-num-seqs', '--enable-prefix-caching', '--enable-auto-tool-choice', '--tool-call-parser', '--served-model-name', '--api-key', '--tensor-parallel-size'] as $flag) {
            $this->assertStringContainsString("- {$flag}", $service, $flag);
        }
    }

    public function test_prompt_token_details_are_switched_on_so_cached_tokens_are_exact(): void
    {
        $this->assertStringContainsString('- --enable-prompt-tokens-details', $this->composeService('vllm'));
    }

    public function test_the_default_context_is_the_budget_window_and_the_default_sequences_cover_the_queue(): void
    {
        $service = $this->composeService('vllm');

        preg_match('/\$\{VLLM_MAX_MODEL_LEN:-(\d+)\}/', $service, $length);
        preg_match('/\$\{VLLM_MAX_NUM_SEQS:-(\d+)\}/', $service, $sequences);

        $this->assertSame((int) config('ai.budget.window'), (int) $length[1]);
        $this->assertGreaterThanOrEqual((int) config('horizon.defaults.ai-interactive.processes'), (int) $sequences[1]);
    }

    public function test_lora_is_off_by_default_and_switched_on_by_a_single_flag_variable(): void
    {
        $service = $this->composeService('vllm');

        $this->assertStringContainsString('${VLLM_LORA_FLAG:---no-enable-lora}', $service);
        $this->assertStringContainsString('VLLM_ALLOW_RUNTIME_LORA_UPDATING', $service);
        $this->assertStringContainsString('./adapters:/adapters:ro', $service);
    }

    public function test_the_service_caches_downloads_and_waits_for_health(): void
    {
        $service = $this->composeService('vllm');

        $this->assertStringContainsString('vllm-hf-cache:/root/.cache/huggingface', $service);
        $this->assertStringContainsString('http://localhost:8000/health', $service);
        $this->assertMatchesRegularExpression('/start_period: \d+s/', $service);
    }

    public function test_the_hugging_face_token_comes_from_the_environment_and_is_not_written_in_the_file(): void
    {
        $this->assertStringContainsString('HF_TOKEN: ${HF_TOKEN:-}', $this->composeService('vllm'));
    }

    public function test_the_compose_defaults_agree_with_the_provider_config_and_env_example(): void
    {
        $service = $this->composeService('vllm');
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match('/\$\{VLLM_MODEL_ALIAS:-([^}]+)\}/', $service, $alias);
        preg_match('/\$\{VLLM_API_KEY:-([^}]+)\}/', $service, $key);
        preg_match('/\$\{VLLM_TOOL_PARSER:-([^}]+)\}/', $service, $parser);

        $this->assertSame($alias[1], config('ai.providers.vllm.model'));
        $this->assertSame($key[1], config('ai.providers.vllm.api_key'));
        $this->assertStringContainsString("VLLM_MODEL_ALIAS={$alias[1]}", $example);
        $this->assertStringContainsString("VLLM_TOOL_PARSER={$parser[1]}", $example);
    }

    public function test_the_adapters_directory_is_kept_but_its_contents_are_not_committed(): void
    {
        $this->assertFileExists(base_path('adapters/.gitkeep'));
        $this->assertStringContainsString('/adapters/*', (string) file_get_contents(base_path('.gitignore')));
        $this->assertStringContainsString('!/adapters/.gitkeep', (string) file_get_contents(base_path('.gitignore')));
    }

    public function test_the_default_hosts_do_not_collide_with_another_providers_port(): void
    {
        $this->assertSame('http://127.0.0.1:8001/v1', config('ai.providers.vllm.base_url'));
        $this->assertNotSame(config('ai.providers.local-llama.base_url'), config('ai.providers.vllm.base_url'));
    }

    private function composeService(string $name): string
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        preg_match('/^  '.preg_quote($name, '/').':\n(?<body>(?:    .*\n|\n)+)/m', $compose, $matches);

        return $matches['body'] ?? '';
    }
}
