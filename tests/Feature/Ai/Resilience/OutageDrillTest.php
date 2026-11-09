<?php

namespace Tests\Feature\Ai\Resilience;

use App\Ai\AiManager;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Replies\AnswerQuestion;
use App\Ai\Resilience\CircuitBreaker;
use App\Models\ModelCall;
use App\Models\Reply;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesStreamedModel;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class OutageDrillTest extends TestCase
{
    use FakesStreamedModel;
    use FakesToolModel;
    use RefreshDatabase;

    private bool $localIsUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        CarbonImmutable::setTestNow('2026-11-09 14:06:00');

        config()->set('ai.breaker', [
            'enabled' => true,
            'threshold' => 3,
            'open_seconds' => 60,
            'memory_seconds' => 600,
            'store' => null,
        ]);
        config()->set('ai.failover', []);
        config()->set('ai.providers.frontier', [
            'driver' => 'openai-compatible',
            'base_url' => 'http://frontier.test/v1',
            'api_key' => 'key',
            'model' => 'frontier-model',
        ]);

        app(AiManager::class)->forget();

        Http::fake(fn (Request $request) => $this->respond($request));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_while_the_model_server_is_down_every_reply_completes_with_the_honest_message_and_no_job_fails(): void
    {
        $this->localIsUp = false;

        $replies = $this->askThree();

        foreach ($replies as $reply) {
            $this->assertSame(Reply::STATUS_COMPLETED, $reply->status);
            $this->assertSame(SupportPrompt::unavailableMessage(), $reply->content);
        }

        $this->assertSame(3, ModelCall::query()->where('provider', 'degraded')->count());
        $this->assertSame(0, ModelCall::query()->where('finish_reason', 'job_failed')->count());
        $this->assertSame(0, ModelCall::query()->where('provider', 'frontier')->count());
    }

    public function test_the_breaker_opens_after_three_failed_questions_and_the_fourth_never_touches_the_server(): void
    {
        $this->localIsUp = false;

        $this->askThree();

        $this->assertTrue((new CircuitBreaker)->isOpen('local'));

        Http::fake(fn (Request $request) => $this->respond($request));

        $this->ask();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'localhost:11434'));
    }

    public function test_with_a_fallback_configured_the_customer_gets_a_real_answer_from_the_fallback(): void
    {
        config()->set('ai.failover', ['local' => 'frontier']);
        app(AiManager::class)->forget();

        $this->localIsUp = false;

        $replies = $this->askThree();

        foreach ($replies as $reply) {
            $this->assertSame('From the frontier model.', $reply->content);
        }

        $this->assertSame(3, ModelCall::query()->where('provider', 'frontier')->count());
        $this->assertSame(0, ModelCall::query()->where('provider', 'degraded')->count());
    }

    public function test_when_the_server_returns_the_breaker_closes_on_the_first_success_and_local_answers_again(): void
    {
        $this->localIsUp = false;

        $this->askThree();

        $this->localIsUp = true;

        CarbonImmutable::setTestNow('2026-11-09 14:07:05');

        $reply = $this->ask();

        $this->assertSame('From the local model.', $reply->content);
        $this->assertFalse((new CircuitBreaker)->isOpen('local'));
        $this->assertSame(0, (new CircuitBreaker)->state('local')->failures);
        $this->assertSame(1, ModelCall::query()->where('provider', 'local')->count());
    }

    public function test_ai_stats_shows_the_breaker_and_where_the_replies_were_routed(): void
    {
        $this->localIsUp = false;

        $this->askThree();

        $this->artisan('ai:stats', ['--feature' => 'support.reply', '--since' => '5m'])
            ->expectsOutputToContain('breaker: local OPEN (3 consecutive ProviderUnavailable at 14:06:00; retry at 14:07:00)')
            ->expectsOutputToContain('routed: degraded 3 · failed jobs 0')
            ->assertSuccessful();
    }

    public function test_a_single_user_replay_still_reports_an_unreachable_model_as_a_failure(): void
    {
        $this->localIsUp = false;

        $user = $this->customerUser();

        $script = tempnam(sys_get_temp_dir(), 'replay');

        file_put_contents($script, json_encode(['messages' => ['one'], 'followUp' => '']));

        $this->artisan('conversation:replay', ['script' => $script, '--user' => $user->id])
            ->expectsOutputToContain('HTTP 503')
            ->assertFailed();

        unlink($script);
    }

    private function askThree(): array
    {
        return [$this->ask(), $this->ask(), $this->ask()];
    }

    private function ask(): Reply
    {
        $reply = Reply::factory()
            ->forUser($this->customerUser())
            ->create();

        AnswerQuestion::dispatchSync($reply->id);

        return $reply->refresh();
    }

    private function respond(Request $request): mixed
    {
        if (str_contains($request->url(), 'frontier.test')) {
            return Http::response($this->streamedAnswer('From the frontier model.'), 200, ['Content-Type' => 'text/event-stream']);
        }

        if (! $this->localIsUp) {
            return Http::response('model server is down', 503);
        }

        return Http::response($this->streamedAnswer('From the local model.'), 200, ['Content-Type' => 'text/event-stream']);
    }
}
