<?php

namespace Tests\Unit\Ai\Resilience;

use App\Ai\Resilience\BreakerState;
use App\Ai\Resilience\CircuitBreaker;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-09 14:06:00');

        config()->set('ai.breaker', [
            'enabled' => true,
            'threshold' => 3,
            'open_seconds' => 60,
            'memory_seconds' => 600,
            'store' => null,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_starts_closed(): void
    {
        $breaker = new CircuitBreaker;

        $this->assertSame(BreakerState::CLOSED, $breaker->state('vllm')->status);
        $this->assertFalse($breaker->isOpen('vllm'));
    }

    public function test_two_failures_leave_it_closed_and_the_third_opens_it_for_sixty_seconds(): void
    {
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('vllm');
        $breaker->recordFailure('vllm');

        $this->assertFalse($breaker->isOpen('vllm'));

        $state = $breaker->recordFailure('vllm');

        $this->assertTrue($breaker->isOpen('vllm'));
        $this->assertSame(3, $state->failures);
        $this->assertSame('14:06:00', $state->openedAt->format('H:i:s'));
        $this->assertSame('14:07:00', $state->retryAt->format('H:i:s'));
    }

    public function test_a_success_between_failures_resets_the_count_so_only_consecutive_failures_count(): void
    {
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('vllm');
        $breaker->recordFailure('vllm');
        $breaker->recordSuccess('vllm');
        $breaker->recordFailure('vllm');
        $breaker->recordFailure('vllm');

        $this->assertFalse($breaker->isOpen('vllm'));
    }

    public function test_after_the_window_it_is_half_open_and_one_failure_reopens_it(): void
    {
        $breaker = new CircuitBreaker;

        foreach (range(1, 3) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        CarbonImmutable::setTestNow('2026-11-09 14:07:01');

        $this->assertSame(BreakerState::HALF_OPEN, $breaker->state('vllm')->status);
        $this->assertFalse($breaker->isOpen('vllm'));

        $breaker->recordFailure('vllm');

        $this->assertTrue($breaker->isOpen('vllm'));
        $this->assertSame('14:08:01', $breaker->state('vllm')->retryAt->format('H:i:s'));
    }

    public function test_the_first_success_after_the_window_closes_it(): void
    {
        $breaker = new CircuitBreaker;

        foreach (range(1, 3) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        CarbonImmutable::setTestNow('2026-11-09 14:07:01');

        $breaker->recordSuccess('vllm');

        $this->assertSame(BreakerState::CLOSED, $breaker->state('vllm')->status);
        $this->assertSame(0, $breaker->state('vllm')->failures);
    }

    public function test_providers_have_separate_breakers(): void
    {
        $breaker = new CircuitBreaker;

        foreach (range(1, 3) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        $this->assertTrue($breaker->isOpen('vllm'));
        $this->assertFalse($breaker->isOpen('frontier'));
    }

    public function test_it_can_be_turned_off(): void
    {
        config()->set('ai.breaker.enabled', false);

        $breaker = new CircuitBreaker;

        foreach (range(1, 5) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        $this->assertFalse($breaker->isOpen('vllm'));
    }

    public function test_the_threshold_and_window_come_from_config(): void
    {
        config()->set('ai.breaker.threshold', 1);
        config()->set('ai.breaker.open_seconds', 10);

        $breaker = new CircuitBreaker;

        $state = $breaker->recordFailure('vllm');

        $this->assertTrue($state->isOpen());
        $this->assertSame('14:06:10', $state->retryAt->format('H:i:s'));
    }

    public function test_the_open_state_describes_itself_the_way_ai_stats_prints_it(): void
    {
        $breaker = new CircuitBreaker;

        foreach (range(1, 3) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        $this->assertSame('OPEN (3 consecutive ProviderUnavailable at 14:06:00; retry at 14:07:00)', $breaker->state('vllm')->describe(3));
        $this->assertSame('closed', BreakerState::closed()->describe(3));
    }

    public function test_reset_closes_it(): void
    {
        $breaker = new CircuitBreaker;

        foreach (range(1, 3) as $ignored) {
            $breaker->recordFailure('vllm');
        }

        $breaker->reset('vllm');

        $this->assertFalse($breaker->isOpen('vllm'));
    }
}
