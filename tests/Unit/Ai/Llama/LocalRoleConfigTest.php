<?php

namespace Tests\Unit\Ai\Llama;

use Tests\TestCase;

class LocalRoleConfigTest extends TestCase
{
    public function test_the_local_role_defaults_to_the_ollama_provider(): void
    {
        $config = $this->configWith(null);

        $this->assertSame('local', $config['features']['support.reply']['provider']);
        $this->assertSame('local', $config['tiers']['small']);
    }

    public function test_the_llama_setting_points_every_local_feature_and_the_small_tier_at_llama_server(): void
    {
        $config = $this->configWith('llama');

        foreach (['ticket.classify', 'ticket.extract', 'support.reply', 'conversation.summarize'] as $feature) {
            $this->assertSame('local-llama', $config['features'][$feature]['provider'], $feature);
        }

        $this->assertSame('local-llama', $config['tiers']['small']);
    }

    public function test_features_that_are_not_the_local_role_are_left_alone(): void
    {
        $config = $this->configWith('llama');

        $this->assertSame('vision', $config['features']['invoice.extract']['provider']);
        $this->assertSame('frontier', $config['features']['support.reply']['escalate_to']);
        $this->assertSame('frontier', $config['tiers']['frontier']);
    }

    public function test_any_other_value_keeps_ollama(): void
    {
        $this->assertSame('local', $this->configWith('ollama')['tiers']['small']);
        $this->assertSame('local', $this->configWith('vllm')['tiers']['small']);
    }

    public function test_the_llama_server_provider_is_a_separate_provider_so_both_can_be_compared(): void
    {
        $providers = config('ai.providers');

        $this->assertSame('llama-server', $providers['local-llama']['driver']);
        $this->assertNotSame($providers['local']['base_url'], $providers['local-llama']['base_url']);
    }

    private function configWith(?string $role): array
    {
        $previous = $_SERVER['AI_LOCAL_PROVIDER'] ?? null;

        $previousEnv = $_ENV['AI_LOCAL_PROVIDER'] ?? null;

        if ($role === null) {
            unset($_SERVER['AI_LOCAL_PROVIDER'], $_ENV['AI_LOCAL_PROVIDER']);
        }

        if ($role !== null) {
            $_SERVER['AI_LOCAL_PROVIDER'] = $role;
            $_ENV['AI_LOCAL_PROVIDER'] = $role;
        }

        try {
            return require config_path('ai.php');
        } finally {
            $this->restore('_SERVER', $previous);
            $this->restore('_ENV', $previousEnv);
        }
    }

    private function restore(string $superglobal, ?string $value): void
    {
        if ($value === null) {
            unset($GLOBALS[$superglobal]['AI_LOCAL_PROVIDER']);

            return;
        }

        $GLOBALS[$superglobal]['AI_LOCAL_PROVIDER'] = $value;
    }
}
