<?php

namespace Tests\Unit\Ai\Decorators;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Decorators\RetryingModel;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Resilience\CircuitBreaker;
use Illuminate\Support\Sleep;
use Tests\Support\ScriptedModel;
use Tests\TestCase;

class RetryingModelBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config()->set('ai.breaker', [
            'enabled' => true,
            'threshold' => 3,
            'open_seconds' => 60,
            'memory_seconds' => 600,
            'store' => null,
        ]);
    }

    public function test_three_calls_that_each_exhaust_their_retries_open_the_breaker_and_the_fourth_never_reaches_the_server(): void
    {
        $inner = new ScriptedModel('vllm', [new ProviderUnavailable('down')]);

        $model = new RetryingModel($inner, breaker: new CircuitBreaker);

        foreach (range(1, 3) as $ignored) {
            $this->assertThrows(fn () => $model->chat($this->request()), ProviderUnavailable::class);
        }

        $this->assertSame(9, $inner->calls);

        try {
            $model->chat($this->request());

            $this->fail('Expected the open circuit to refuse the call');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('vllm circuit open', $exception->getMessage());
        }

        $this->assertSame(9, $inner->calls);
    }

    public function test_a_success_closes_the_breaker_and_clears_the_count(): void
    {
        $inner = new ScriptedModel('vllm', [new ProviderUnavailable('down'), new ProviderUnavailable('down'), 'recovered']);

        $model = new RetryingModel($inner, breaker: new CircuitBreaker);

        $this->assertSame('recovered', $model->chat($this->request())->content);
        $this->assertSame(0, (new CircuitBreaker)->state('vllm')->failures);
    }

    public function test_errors_that_are_not_provider_unavailability_do_not_count(): void
    {
        $inner = new ScriptedModel('vllm', [new InvalidRequest('bad'), new RateLimited('slow')]);

        $model = new RetryingModel($inner, breaker: new CircuitBreaker);

        foreach (range(1, 5) as $ignored) {
            try {
                $model->chat($this->request());
            } catch (InvalidRequest|RateLimited) {
            }
        }

        $this->assertFalse((new CircuitBreaker)->isOpen('vllm'));
    }

    public function test_a_stream_that_fails_before_any_event_counts_and_an_open_breaker_refuses_the_next_stream(): void
    {
        $inner = new ScriptedModel('vllm', [new ProviderUnavailable('down')]);

        $model = new RetryingModel($inner, breaker: new CircuitBreaker);

        foreach (range(1, 3) as $ignored) {
            $this->assertThrows(fn () => iterator_to_array($model->stream($this->request())), ProviderUnavailable::class);
        }

        $calls = $inner->calls;

        $this->assertThrows(fn () => iterator_to_array($model->stream($this->request())), ProviderUnavailable::class);
        $this->assertSame($calls, $inner->calls);
    }

    public function test_a_successful_stream_returns_its_response_and_closes_the_breaker(): void
    {
        $inner = new ScriptedModel('vllm', ['hello']);

        $model = new RetryingModel($inner, breaker: new CircuitBreaker);

        $stream = $model->stream($this->request());

        iterator_to_array($stream, false);

        $this->assertSame('hello', $stream->getReturn()->content);
    }

    public function test_without_a_breaker_nothing_is_refused(): void
    {
        $inner = new ScriptedModel('vllm', [new ProviderUnavailable('down')]);

        $model = new RetryingModel($inner);

        foreach (range(1, 4) as $ignored) {
            $this->assertThrows(fn () => $model->chat($this->request()), ProviderUnavailable::class);
        }

        $this->assertSame(12, $inner->calls);
    }

    public function test_with_inner_keeps_the_breaker(): void
    {
        $inner = new ScriptedModel('vllm', [new ProviderUnavailable('down')]);

        $model = (new RetryingModel(new ScriptedModel('other'), breaker: new CircuitBreaker))->withInner($inner);

        foreach (range(1, 3) as $ignored) {
            $this->assertThrows(fn () => $model->chat($this->request()), ProviderUnavailable::class);
        }

        $this->assertTrue((new CircuitBreaker)->isOpen('vllm'));
    }

    private function request(): ChatRequest
    {
        return new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'test', profile: ChatRequest::NO_PROFILE);
    }
}
