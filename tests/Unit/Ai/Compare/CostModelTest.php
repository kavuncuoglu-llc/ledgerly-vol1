<?php

namespace Tests\Unit\Ai\Compare;

use App\Ai\Compare\Cost;
use App\Ai\Compare\CostModel;
use App\Ai\Contracts\Usage;
use Tests\TestCase;

class CostModelTest extends TestCase
{
    public function test_a_hosted_provider_with_a_rate_is_priced_per_million_tokens(): void
    {
        config()->set('ai.providers.priced', ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm1']);
        config()->set('ai.rates.priced', ['m1' => ['in' => 3.0, 'out' => 15.0]]);

        $cost = app(CostModel::class)->of('priced', new Usage(1000, 200), 1234.0);

        $this->assertSame(Cost::BASIS_RATE, $cost->basis);
        $this->assertEqualsWithDelta(0.006, $cost->usd, 1e-9);
    }

    public function test_a_local_provider_with_a_gpu_hour_cost_pays_for_the_wall_clock_time(): void
    {
        config()->set('ai.providers.local.gpu_hour_cost', 0.72);

        $cost = app(CostModel::class)->of('local', new Usage(1000, 200), 5000.0);

        $this->assertSame(Cost::BASIS_GPU, $cost->basis);
        $this->assertEqualsWithDelta(0.001, $cost->usd, 1e-9);
    }

    public function test_a_local_provider_without_any_price_is_free_and_marked_as_such(): void
    {
        $cost = app(CostModel::class)->of('local', new Usage(1000, 200), 5000.0);

        $this->assertSame(Cost::BASIS_FREE, $cost->basis);
        $this->assertSame(0.0, $cost->usd);
        $this->assertTrue($cost->isKnown());
    }

    public function test_a_hosted_provider_without_a_rate_is_unknown_not_free(): void
    {
        config()->set('ai.providers.unpriced', ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm2']);

        $cost = app(CostModel::class)->of('unpriced', new Usage(1000, 200), 100.0);

        $this->assertSame(Cost::BASIS_UNKNOWN, $cost->basis);
        $this->assertFalse($cost->isKnown());
    }

    public function test_a_remote_openai_compatible_server_without_a_rate_is_unknown(): void
    {
        config()->set('ai.providers.remote', ['driver' => 'openai-compatible', 'base_url' => 'https://gpu.example.com/v1', 'api_key' => 'k', 'model' => 'm3']);

        $this->assertSame(Cost::BASIS_UNKNOWN, app(CostModel::class)->of('remote', new Usage(10, 10), 10.0)->basis);
    }
}
