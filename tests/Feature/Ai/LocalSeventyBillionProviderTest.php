<?php

namespace Tests\Feature\Ai;

use App\Ai\AiManager;
use App\Ai\Tiers\ModelTier;
use App\Ai\Tiers\ModelTiers;
use Tests\TestCase;

class LocalSeventyBillionProviderTest extends TestCase
{
    public function test_the_provider_points_at_the_modelfile_name_on_the_same_ollama(): void
    {
        $config = app(AiManager::class)->providerConfig('local-70b');

        $this->assertSame('ledgerly-support-70b', $config['model']);
        $this->assertSame(config('ai.providers.local.base_url'), $config['base_url']);
        $this->assertSame(300, $config['timeout']);
        $this->assertTrue(app(AiManager::class)->isConfigured('local-70b'));
    }

    public function test_the_mid_tier_is_not_pointed_at_it_unless_you_say_so(): void
    {
        $this->assertSame('mid', config('ai.tiers.mid'));
    }

    public function test_naming_it_as_the_mid_provider_routes_the_mid_tier_to_it(): void
    {
        config()->set('ai.tiers.mid', 'local-70b');

        $this->assertSame('local-70b', app(ModelTiers::class)->providerFor(ModelTier::Mid));
    }

    public function test_the_modelfile_names_the_base_model_and_the_apps_context_window(): void
    {
        $modelfile = (string) file_get_contents(base_path('ops/ollama/Modelfile.ledgerly-support-70b'));

        $this->assertStringContainsString('FROM llama3.1:70b-instruct-q4_K_M', $modelfile);
        $this->assertStringContainsString('PARAMETER num_ctx '.config('ai.budget.window'), $modelfile);
    }
}
