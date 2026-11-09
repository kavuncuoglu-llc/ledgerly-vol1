<?php

namespace Tests\Feature\Console;

use App\Ai\Doctor\BindProbe;
use App\Ai\Hardware\HostInspector;
use Tests\Support\FakeBindProbe;
use Tests\Support\FakeHost;
use Tests\TestCase;

class AiDoctorProfileTest extends TestCase
{
    private const NVIDIA = [
        'nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits' => 'NVIDIA L4, 23034',
        'docker compose version --short' => '2.40.0',
        'docker info --format {{json .Runtimes}}' => '{"nvidia":{"path":"nvidia-container-runtime"},"runc":{"path":"runc"}}',
    ];

    public function test_a_gpu_profile_on_a_machine_with_an_nvidia_card_and_the_toolkit_passes(): void
    {
        config()->set('ai.tiers.small', 'vllm');
        $this->machine(self::NVIDIA);

        $this->artisan('ai:doctor', ['--profile' => 'vllm'])
            ->expectsOutputToContain('✓ profile vllm is declared in')
            ->expectsOutputToContain('✓ docker compose 2.40.0')
            ->expectsOutputToContain('✓ NVIDIA GPU for profile vllm: NVIDIA L4')
            ->expectsOutputToContain('✓ docker has the nvidia runtime')
            ->expectsOutputToContain('✓ the local role (AI_LOCAL_PROVIDER) points at vllm')
            ->expectsOutputToContain('✓ 127.0.0.1:8001 is free for profile vllm')
            ->expectsOutputToContain('0 warnings, 0 failures')
            ->assertSuccessful();
    }

    public function test_a_gpu_profile_on_a_mac_fails_on_the_accelerator(): void
    {
        $this->app->instance(HostInspector::class, FakeHost::macStudio(32, chip: 'Apple M1 Pro'));
        $this->app->instance(BindProbe::class, new FakeBindProbe);

        config()->set('ai.compose.file', base_path('docker-compose.yml'));

        $this->artisan('ai:doctor', ['--profile' => 'triton'])
            ->expectsOutputToContain('✗ profile triton needs an NVIDIA GPU; this machine reports Apple M1 Pro')
            ->assertFailed();
    }

    public function test_the_book_names_resolve_to_the_compose_profiles(): void
    {
        $this->machine([...self::NVIDIA]);

        config()->set('ai.tiers.small', 'local');

        $this->artisan('ai:doctor', ['--profile' => 'ollama'])
            ->expectsOutputToContain('✓ profile linux-gpu is declared in')
            ->assertSuccessful();
    }

    public function test_a_local_role_that_points_elsewhere_is_a_warning(): void
    {
        config()->set('ai.tiers.small', 'local');
        $this->machine(self::NVIDIA);

        $this->artisan('ai:doctor', ['--profile' => 'triton'])
            ->expectsOutputToContain('⚠ profile triton serves provider triton, but the local role points at local')
            ->assertSuccessful();
    }

    public function test_a_port_that_already_answers_is_a_warning(): void
    {
        config()->set('ai.tiers.small', 'vllm');
        $this->machine(self::NVIDIA, accepting: ['127.0.0.1']);

        $this->artisan('ai:doctor', ['--profile' => 'vllm'])
            ->expectsOutputToContain('⚠ 127.0.0.1:8001 already answers')
            ->assertSuccessful();
    }

    public function test_missing_docker_and_a_missing_nvidia_runtime_are_reported(): void
    {
        config()->set('ai.tiers.small', 'vllm');
        $this->machine(['nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits' => 'NVIDIA L4, 23034']);

        $this->artisan('ai:doctor', ['--profile' => 'vllm'])
            ->expectsOutputToContain('✗ docker compose is not available on this machine')
            ->expectsOutputToContain('⚠ docker does not list an nvidia runtime')
            ->assertFailed();
    }

    public function test_a_profile_the_compose_file_does_not_declare_fails(): void
    {
        $this->machine(self::NVIDIA, compose: "services:\n  vllm:\n    profiles: [\"vllm\"]\n");

        $this->artisan('ai:doctor', ['--profile' => 'triton'])
            ->expectsOutputToContain('declares no service in profile triton (it has vllm)')
            ->assertFailed();
    }

    public function test_an_unknown_profile_lists_the_known_ones(): void
    {
        $this->artisan('ai:doctor', ['--profile' => 'sglang'])
            ->expectsOutputToContain('Unknown Compose profile [sglang]. Known profiles: linux-gpu, llama-gpu, llama-cpu, vllm, triton, monitoring.')
            ->assertFailed();
    }

    public function test_the_shipped_compose_file_declares_every_profile_the_config_knows(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        foreach (array_keys(config('ai.compose.profiles')) as $profile) {
            $this->assertMatchesRegularExpression('/profiles:\s*\[[^\]]*"'.preg_quote($profile, '/').'"/', $compose, $profile);
        }
    }

    private function machine(array $commands, array $accepting = [], string $compose = ''): void
    {
        config()->set('ai.compose.file', base_path('docker-compose.yml'));

        $this->app->instance(HostInspector::class, new FakeHost('Linux', $commands, [
            base_path('docker-compose.yml') => $compose ?: (string) file_get_contents(base_path('docker-compose.yml')),
        ]));

        $this->app->instance(BindProbe::class, new FakeBindProbe(accepting: $accepting));
    }
}
