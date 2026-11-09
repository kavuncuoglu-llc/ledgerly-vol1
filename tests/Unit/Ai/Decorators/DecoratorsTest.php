<?php

namespace Tests\Unit\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Decorators\ChatModelDecorator;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Decorators\RetryingModel;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Exceptions\UnknownSamplingProfile;
use App\Ai\Http\CallDetails;
use App\Ai\Testing\FakeLedger;
use App\Ai\Testing\FakeScript;
use Generator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DecoratorsTest extends TestCase
{
    private FakeLedger $ledger;

    private FakeScript $script;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);

        $this->ledger = new FakeLedger;
        $this->script = new FakeScript($this->ledger);
    }

    public function test_the_profile_merging_model_applies_the_assistant_profile_by_default(): void
    {
        $this->script->respondWith('ok');

        $this->profiled()->chat($this->request());

        $sent = $this->ledger->sent()[0]['request'];
        $this->assertSame(0.7, $sent->options['temperature']);
        $this->assertSame(ChatRequest::NO_PROFILE, $sent->profile);
    }

    public function test_the_profile_merging_model_uses_the_features_map_when_the_request_names_no_profile(): void
    {
        $this->script->respondWith('ok');

        $this->profiled()->chat($this->request(feature: 'ticket.classify'));

        $this->assertSame(0, $this->ledger->sent()[0]['request']->options['temperature']);
    }

    public function test_an_explicit_profile_beats_the_features_map_and_explicit_options_beat_the_profile(): void
    {
        $this->script->respondWith('ok');

        $this->profiled()->chat($this->request(feature: 'ticket.classify', options: ['profile' => 'creative', 'temperature' => 0.1]));

        $options = $this->ledger->sent()[0]['request']->options;
        $this->assertSame(0.1, $options['temperature']);
        $this->assertSame(0.95, $options['top_p']);
    }

    public function test_a_null_profile_sends_no_sampling_values(): void
    {
        $this->script->respondWith('ok');

        $this->profiled()->chat($this->request(options: ['profile' => null]));

        $this->assertSame([], $this->ledger->sent()[0]['request']->options);
    }

    public function test_an_unknown_profile_fails_before_anything_is_sent(): void
    {
        $this->expectException(UnknownSamplingProfile::class);

        try {
            $this->profiled()->chat($this->request(options: ['profile' => 'nope']));
        } finally {
            $this->assertSame([], $this->ledger->sent());
        }
    }

    public function test_the_retrying_model_retries_a_transient_failure_and_then_succeeds(): void
    {
        $this->script->failWith(new ProviderUnavailable('busy'), times: 2)->respondWith('finally');

        $response = (new RetryingModel($this->fake()))->chat($this->request());

        $this->assertSame('finally', $response->content);
        $this->assertCount(3, $this->ledger->sent());
        Sleep::assertSleptTimes(2);
    }

    public function test_the_retrying_model_gives_up_after_three_attempts(): void
    {
        $this->script->failWith(new RateLimited('slow down'), times: 5);

        try {
            (new RetryingModel($this->fake()))->chat($this->request());

            $this->fail('Expected RateLimited.');
        } catch (RateLimited) {
            $this->assertCount(3, $this->ledger->sent());
        }
    }

    public function test_the_retrying_model_does_not_retry_a_caller_mistake(): void
    {
        $this->script->failWith(new InvalidRequest('bad key', 401), times: 3);

        try {
            (new RetryingModel($this->fake()))->chat($this->request());

            $this->fail('Expected InvalidRequest.');
        } catch (InvalidRequest) {
            $this->assertCount(1, $this->ledger->sent());
        }
    }

    public function test_the_retrying_model_honors_retry_after(): void
    {
        $this->script->failWith(new RateLimited('slow down', 429, 7))->respondWith('ok');

        (new RetryingModel($this->fake()))->chat($this->request());

        Sleep::assertSequence([Sleep::for(7000)->milliseconds()]);
    }

    public function test_a_stream_that_fails_before_its_first_event_is_retried(): void
    {
        $this->script->failWith(new ProviderUnavailable('busy'))->respondWithStream(['a', 'b']);

        $stream = (new RetryingModel($this->fake()))->stream($this->request());
        $events = iterator_to_array($stream, false);

        $this->assertSame(['a', 'b'], array_map(fn (Delta $delta): string => $delta->text, array_values(array_filter($events, fn (mixed $event): bool => $event instanceof Delta))));
        $this->assertSame('ab', $stream->getReturn()->content);
        $this->assertCount(2, $this->ledger->sent());
    }

    public function test_a_stream_that_fails_after_it_started_is_not_retried(): void
    {
        $failing = new class($this->fake()) extends ChatModelDecorator
        {
            public function withInner(ChatModel $inner): static
            {
                return new self($inner);
            }

            public function chat(ChatRequest $request): ChatResponse
            {
                return $this->inner->chat($request);
            }

            public function stream(ChatRequest $request): Generator
            {
                yield new Delta('partial');

                throw new ProviderUnavailable('dropped');
            }
        };

        $this->expectException(ProviderUnavailable::class);

        iterator_to_array((new RetryingModel($failing))->stream($this->request()), false);
    }

    public function test_the_usage_logging_model_dispatches_one_event_per_logical_call_with_the_provider_name(): void
    {
        $this->script->respondWith('ok');

        $response = (new UsageLoggingModel($this->fake('frontier')))->chat($this->request(
            feature: 'support.reply',
            details: new CallDetails(contextBreakdown: ['system' => 4], semanticCacheHit: false),
        ));

        $this->assertSame(FinishReason::Stop, $response->finishReason);
        Event::assertDispatchedTimes(ModelCalled::class, 1);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->provider === 'frontier'
            && $event->feature === 'support.reply'
            && $event->finishReason === 'stop'
            && $event->contextBreakdown === ['system' => 4]
            && $event->semanticCacheHit === false);
    }

    public function test_the_usage_logging_model_logs_a_stream_once_with_the_time_to_the_first_delta(): void
    {
        $this->script->respondWithStream(['Hel', 'lo']);

        $stream = (new UsageLoggingModel($this->fake()))->stream($this->request());
        $events = iterator_to_array($stream, false);

        $this->assertInstanceOf(Completed::class, end($events));
        Event::assertDispatchedTimes(ModelCalled::class, 1);
        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->ttftMs !== null && $event->ttftMs <= $event->latencyMs);
    }

    public function test_the_usage_logging_model_does_not_log_a_failed_call(): void
    {
        $this->script->failWith(new InvalidRequest('no', 400));

        try {
            (new UsageLoggingModel($this->fake()))->chat($this->request());
        } catch (InvalidRequest) {
        }

        Event::assertNotDispatched(ModelCalled::class);
    }

    private function fake(string $name = 'fake'): FakeDriver
    {
        return new FakeDriver(['name' => $name], $this->ledger);
    }

    private function profiled(): ProfileMergingModel
    {
        return new ProfileMergingModel($this->fake());
    }

    private function request(string $feature = 'test', array $options = [], ?CallDetails $details = null): ChatRequest
    {
        return ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], $options, $feature, details: $details);
    }
}
