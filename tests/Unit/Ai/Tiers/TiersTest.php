<?php

namespace Tests\Unit\Ai\Tiers;

use App\Ai\Support\Router;
use App\Ai\Tiers\ModelTier;
use App\Ai\Tiers\ModelTiers;
use App\Ai\Tiers\RouteSignals;
use App\Enums\TicketCategory;
use Tests\TestCase;

class TiersTest extends TestCase
{
    public function test_a_clean_ordinary_question_stays_on_the_small_tier(): void
    {
        $this->assertSame(ModelTier::Small, $this->router()->tierFor(new RouteSignals(agreement: 1.0)));
    }

    public function test_low_agreement_a_multi_part_question_or_a_dispute_moves_up_to_mid(): void
    {
        $this->assertSame(ModelTier::Mid, $this->router()->tierFor(new RouteSignals(agreement: 0.5)));
        $this->assertSame(ModelTier::Mid, $this->router()->tierFor(new RouteSignals(agreement: 1.0, multiAsk: true)));
        $this->assertSame(ModelTier::Mid, $this->router()->tierFor(new RouteSignals(agreement: 1.0, isDispute: true)));
    }

    public function test_two_unresolved_rounds_or_a_human_flag_go_straight_to_frontier(): void
    {
        $this->assertSame(ModelTier::Frontier, $this->router()->tierFor(new RouteSignals(agreement: 1.0, unresolvedRounds: 2)));
        $this->assertSame(ModelTier::Mid, $this->router()->tierFor(new RouteSignals(agreement: 1.0, unresolvedRounds: 1, multiAsk: true)));
        $this->assertSame(ModelTier::Frontier, $this->router()->tierFor(new RouteSignals(agreement: 0.2, humanFlagged: true)));
    }

    public function test_the_thresholds_come_from_config(): void
    {
        config()->set('ai.routing.frontier_after_rounds', 1);
        config()->set('ai.routing.mid_below_agreement', 0.9);

        $this->assertSame(ModelTier::Frontier, $this->router()->tierFor(new RouteSignals(agreement: 1.0, unresolvedRounds: 1)));
        $this->assertSame(ModelTier::Mid, $this->router()->tierFor(new RouteSignals(agreement: 0.8)));
    }

    public function test_signals_read_the_shape_of_the_question(): void
    {
        $this->assertTrue(RouteSignals::for('Why twice? And can I get a refund?', TicketCategory::Billing, 1.0)->multiAsk);
        $this->assertFalse(RouteSignals::for('Why was I charged?', TicketCategory::Billing, 1.0)->multiAsk);
        $this->assertTrue(RouteSignals::for('I want to dispute this charge', TicketCategory::Billing, 1.0)->isDispute);
        $this->assertTrue(RouteSignals::for('I was charged twice', TicketCategory::Billing, 1.0)->isDispute);
        $this->assertFalse(RouteSignals::for('How do I dispute a bug?', TicketCategory::Bug, 1.0)->isDispute);
        $this->assertFalse(RouteSignals::for('Why was I charged a late fee?', TicketCategory::Billing, 1.0)->isDispute);
    }

    public function test_tiers_resolve_to_the_providers_named_in_config(): void
    {
        $this->configureMid();
        config()->set('ai.providers.frontier.api_key', 'sk');
        config()->set('ai.providers.frontier.model', 'claude-test');
        $tiers = app(ModelTiers::class);

        $this->assertSame('local', $tiers->providerFor(ModelTier::Small));
        $this->assertSame('mid', $tiers->providerFor(ModelTier::Mid));
        $this->assertSame('frontier', $tiers->providerFor(ModelTier::Frontier));
        $this->assertTrue($tiers->isServed(ModelTier::Mid));
    }

    public function test_an_unconfigured_tier_falls_back_to_the_next_tier_down(): void
    {
        $tiers = app(ModelTiers::class);

        $this->assertSame('local', $tiers->providerFor(ModelTier::Mid));
        $this->assertSame('local', $tiers->providerFor(ModelTier::Frontier));
        $this->assertFalse($tiers->isServed(ModelTier::Mid));
        $this->assertSame('mid', $tiers->providerNamed(ModelTier::Mid));
    }

    public function test_the_mid_tier_name_is_configurable(): void
    {
        config()->set('ai.tiers.mid', 'local-70b');
        config()->set('ai.providers.local-70b', ['driver' => 'openai-compatible', 'base_url' => 'http://gpu.test/v1', 'api_key' => 'k', 'model' => 'llama3.3:70b']);

        $this->assertSame('local-70b', app(ModelTiers::class)->providerFor(ModelTier::Mid));
    }

    private function configureMid(): void
    {
        config()->set('ai.providers.mid', ['driver' => 'openai-compatible', 'base_url' => 'http://mid.test/v1', 'api_key' => 'k', 'model' => 'mid-model']);
    }

    private function router(): Router
    {
        return app(Router::class);
    }
}
