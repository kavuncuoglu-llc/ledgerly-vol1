<?php

namespace Tests\Unit\Ai\Ops;

use Tests\TestCase;

class ComposeLayoutTest extends TestCase
{
    private const SERVER_PROFILES = [
        'ollama.yml' => [
            'ollama' => 'ollama',
            'ollama-init' => 'ollama',
        ],
        'llama.yml' => [
            'llama' => 'llama',
            'llama-cpu' => 'llama-cpu',
        ],
        'vllm.yml' => [
            'vllm' => 'vllm',
        ],
        'triton.yml' => [
            'triton' => 'triton',
        ],
        'monitoring.yml' => [
            'prometheus' => 'monitoring',
            'grafana' => 'monitoring',
        ],
    ];

    public function test_every_file_the_book_names_exists(): void
    {
        foreach (['base.yml', 'macos.override.yml', 'ollama.yml', 'llama.yml', 'vllm.yml', 'triton.yml', 'monitoring.yml', '.env.example'] as $file) {
            $this->assertFileExists(base_path("ops/compose/{$file}"));
        }
    }

    public function test_base_extends_the_root_definitions_of_the_infrastructure_services(): void
    {
        $base = $this->compose('base.yml');

        foreach (['postgres', 'redis', 'sidecar'] as $service) {
            $this->assertStringContainsString("  {$service}:\n    extends:\n      file: ../../docker-compose.yml\n      service: {$service}\n", $base);
        }

        $this->assertStringNotContainsString('profiles:', $base);
    }

    public function test_each_server_file_reuses_a_root_service_and_renames_only_the_profile(): void
    {
        foreach (self::SERVER_PROFILES as $file => $services) {
            $contents = $this->compose($file);

            foreach ($services as $service => $profile) {
                $this->assertStringContainsString("  {$service}:\n    extends:\n      file: ../../docker-compose.yml\n      service: {$service}\n    profiles: !override\n      - {$profile}\n", $contents, "{$file} {$service}");
                $this->assertNotSame('', $this->rootService($service), "{$service} is not in docker-compose.yml");
            }
        }
    }

    public function test_the_server_files_define_no_image_command_or_port_of_their_own(): void
    {
        foreach (array_keys(self::SERVER_PROFILES) as $file) {
            $contents = $this->compose($file);

            foreach (['image:', 'command:', 'ports:', 'build:', 'healthcheck:'] as $key) {
                $this->assertStringNotContainsString($key, $contents, "{$file} would drift from docker-compose.yml with {$key}");
            }
        }
    }

    public function test_every_named_volume_and_network_a_file_uses_is_declared_in_that_file(): void
    {
        $expected = [
            'base.yml' => ['internal', 'ledgerly-postgres', 'ledgerly-redis'],
            'ollama.yml' => ['internal', 'ollama-models'],
            'llama.yml' => ['internal'],
            'vllm.yml' => ['internal', 'vllm-hf-cache'],
            'triton.yml' => ['internal', 'triton-hf-cache'],
            'monitoring.yml' => ['internal', 'prometheus-data', 'grafana-data'],
        ];

        foreach ($expected as $file => $names) {
            foreach ($names as $name) {
                $this->assertMatchesRegularExpression('/^  '.preg_quote($name, '/').':$/m', $this->compose($file), "{$file} {$name}");
            }
        }
    }

    public function test_all_files_share_one_project_name_so_the_documented_commands_compose_into_one_stack(): void
    {
        foreach (array_merge(['base.yml', 'macos.override.yml'], array_keys(self::SERVER_PROFILES)) as $file) {
            $this->assertStringStartsWith("name: ledgerly\n", $this->compose($file), $file);
        }
    }

    public function test_the_macos_override_points_containers_at_the_native_ollama(): void
    {
        $override = $this->compose('macos.override.yml');

        $this->assertStringContainsString('http://host.docker.internal:11434/v1', $override);
        $this->assertStringContainsString('host.docker.internal:host-gateway', $override);
        $this->assertStringNotContainsString('profiles:', $override);
    }

    public function test_the_env_example_beside_the_compose_files_is_the_root_one(): void
    {
        $this->assertSame(
            (string) file_get_contents(base_path('.env.example')),
            $this->compose('.env.example'),
        );
    }

    public function test_the_env_file_compose_reads_is_a_link_to_the_root_env_and_stays_trackable(): void
    {
        $link = base_path('ops/compose/.env');

        $this->assertTrue(is_link($link));
        $this->assertSame('../../.env', readlink($link));
        $this->assertStringContainsString("\n!ops/compose/.env\n", (string) file_get_contents(base_path('.gitignore')));
    }

    public function test_the_gpu_session_script_is_executable_strict_and_uses_the_documented_files(): void
    {
        $path = base_path('ops/cloud/gpu-session.sh');
        $script = (string) file_get_contents($path);

        $this->assertTrue(is_executable($path));
        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $script);
        $this->assertStringContainsString("set -euo pipefail\n", $script);
        $this->assertStringContainsString('--down', $script);
        $this->assertStringContainsString('nvidia-smi', $script);
        $this->assertStringContainsString('nvidia-container-toolkit', $script);
        $this->assertStringContainsString('ops/compose/.env.example', $script);
        $this->assertStringContainsString('--profile vllm', $script);
        $this->assertStringContainsString('ai:doctor --provider=vllm', $script);

        foreach (['base.yml', 'vllm.yml', 'monitoring.yml'] as $file) {
            $this->assertStringContainsString("{COMPOSE_DIR}/{$file}", $script);
        }
    }

    public function test_the_ollama_url_the_appendix_names_is_read_and_ai_base_url_still_wins(): void
    {
        $config = (string) file_get_contents(base_path('config/ai.php'));

        $this->assertStringContainsString("\$ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434/v1');", $config);
        $this->assertSame(4, substr_count($config, "env('AI_BASE_URL', \$ollamaUrl)"));
        $this->assertStringContainsString("\nOLLAMA_URL=http://localhost:11434/v1\n", (string) file_get_contents(base_path('.env.example')));
    }

    private function compose(string $file): string
    {
        return (string) file_get_contents(base_path("ops/compose/{$file}"));
    }

    private function rootService(string $name): string
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        preg_match('/^  '.preg_quote($name, '/').':\n(?<body>(?:    .*\n|\n)+)/m', $compose, $matches);

        return $matches['body'] ?? '';
    }
}
