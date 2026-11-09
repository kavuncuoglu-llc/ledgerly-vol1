<?php

namespace Tests\Feature\Support;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class SupportTierRoutingTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
        config()->set('ai.routing.enabled', true);
    }

    public function test_a_billing_dispute_is_answered_by_the_mid_tier_when_one_is_configured(): void
    {
        $this->configureMid();
        $this->fakeBothServers(['billing']);

        $this->ask('I want to dispute a charge on invoice 4438');

        $this->assertSame(['local', 'local', 'local', 'mid'], ModelCall::query()->orderBy('id')->pluck('provider')->all());
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://mid.test'));
    }

    public function test_an_ordinary_question_stays_on_the_small_tier(): void
    {
        $this->configureMid();
        $this->fakeBothServers(['howto']);

        $this->ask('How do I add my logo?');

        $this->assertSame(['local', 'local', 'local', 'local'], ModelCall::query()->orderBy('id')->pluck('provider')->all());
    }

    public function test_without_a_mid_tier_a_dispute_stays_local(): void
    {
        $this->fakeBothServers(['billing']);

        $this->ask('I want to dispute a charge on invoice 4438');

        $this->assertSame(['local', 'local', 'local', 'local'], ModelCall::query()->orderBy('id')->pluck('provider')->all());
    }

    private function configureMid(): void
    {
        config()->set('ai.providers.mid', ['driver' => 'openai-compatible', 'base_url' => 'http://mid.test/v1', 'api_key' => 'k', 'model' => 'mid-model']);
    }

    private function fakeBothServers(array $votes): void
    {
        $script = $this->triageScript(votes: $votes);

        Http::fake(function (Request $request) use ($script) {
            $system = (string) $request['messages'][0]['content'];

            if (str_starts_with($system, 'You classify')) {
                return Http::response($this->triageReply($request, $script));
            }

            return Http::response($this->answerResponse('Here is what I found.'));
        });
    }

    private function ask(string $question): void
    {
        $this->actingAs($this->customerUser())
            ->postJson('support/ask', ['question' => $question])
            ->assertOk();
    }
}
