<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonConfigTest extends TestCase
{
    public function test_interactive_model_work_has_its_own_pool_on_the_ai_queue(): void
    {
        $pool = config('horizon.defaults.ai-interactive');

        $this->assertSame(['ai'], $pool['queue']);
        $this->assertSame('simple', $pool['balance']);
        $this->assertSame('redis', $pool['connection']);
        $this->assertSame(3, $pool['tries']);
    }

    public function test_the_worker_timeout_is_above_the_job_timeout(): void
    {
        $this->assertGreaterThan(config('ai.replies.timeout'), config('horizon.defaults.ai-interactive.timeout'));
    }

    public function test_background_model_work_is_a_small_autoscaled_pool_with_a_long_timeout(): void
    {
        $pool = config('horizon.defaults.ai-batch');

        $this->assertSame(['ai-batch'], $pool['queue']);
        $this->assertSame('auto', $pool['balance']);
        $this->assertSame(1, $pool['minProcesses']);
        $this->assertSame(2, $pool['maxProcesses']);
        $this->assertSame(600, $pool['timeout']);
    }

    public function test_production_sizes_the_interactive_pool_from_ai_concurrency(): void
    {
        $this->assertSame((int) env('AI_CONCURRENCY', 4), config('horizon.environments.production.ai-interactive.processes'));
    }

    public function test_every_pool_belongs_to_every_environment(): void
    {
        foreach (['production', 'local'] as $environment) {
            $this->assertSame(
                ['ai-batch', 'ai-interactive', 'default'],
                collect(config("horizon.environments.{$environment}"))->keys()->sort()->values()->all(),
            );
        }
    }

    public function test_long_waits_on_the_ai_queues_are_watched(): void
    {
        $this->assertSame(30, config('horizon.waits')['redis:ai']);
        $this->assertSame(300, config('horizon.waits')['redis:ai-batch']);
    }

    public function test_the_horizon_dashboard_gate_is_closed_outside_local_unless_listed(): void
    {
        config()->set('ai.horizon_viewers', ['ops@example.com']);

        $this->assertTrue(Gate::forUser((object) ['email' => 'ops@example.com'])->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser((object) ['email' => 'other@example.com'])->allows('viewHorizon'));
    }
}
