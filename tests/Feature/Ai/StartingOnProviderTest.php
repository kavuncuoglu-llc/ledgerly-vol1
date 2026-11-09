<?php

namespace Tests\Feature\Ai;

use App\Ai\Facades\Ai;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class StartingOnProviderTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_a_runner_started_on_a_provider_asks_that_provider_not_the_features_own(): void
    {
        Ai::fake()->respondWith('Answered.');

        $result = app(ToolRunner::class)
            ->startingOn('mid')
            ->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($this->customerUser()), feature: 'support.reply');

        $this->assertSame('Answered.', $result->response->content);
        Ai::assertSent(fn ($request, string $provider): bool => $provider === 'mid');
        Ai::assertNothingSentTo('local');
    }

    public function test_the_choice_survives_restricting_tools_and_setting_an_escalation_point(): void
    {
        Ai::fake()->respondWith('Answered.');

        app(ToolRunner::class)
            ->startingOn('mid')
            ->restrictedTo([])
            ->escalatingAfter(2)
            ->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($this->customerUser()), feature: 'support.reply');

        Ai::assertSent(fn ($request, string $provider): bool => $provider === 'mid');
    }

    public function test_without_a_choice_the_feature_decides(): void
    {
        Ai::fake()->respondWith('Answered.');

        app(ToolRunner::class)->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($this->customerUser()), feature: 'support.reply');

        Ai::assertSent(fn ($request, string $provider): bool => $provider === 'local');
    }
}
