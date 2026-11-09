<?php

namespace Tests\Feature\Console;

use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Facades\Ai;
use App\Ai\Hardware\HostInspector;
use App\Ai\Profile\BackgroundSampler;
use App\Ai\Profile\MemoryProbeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHost;
use Tests\Support\FixedMemoryProbe;
use Tests\Support\FixedMemoryProbeFactory;
use Tests\Support\ScriptedSampler;
use Tests\TestCase;

class AiProfileCommandTest extends TestCase
{
    use RefreshDatabase;

    private ScriptedSampler $sampler;

    private string $prompts;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->sampler = new ScriptedSampler;

        $this->app->instance(BackgroundSampler::class, $this->sampler);
        $this->app->instance(MemoryProbeFactory::class, new FixedMemoryProbeFactory(new FixedMemoryProbe));
        $this->app->instance(HostInspector::class, FakeHost::macStudio(32, chip: 'Apple M1 Pro'));

        Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'llama3.1:8b', 'size' => 4_920_753_328]]])]);

        $this->prompts = sys_get_temp_dir().'/profile-'.uniqid().'.json';

        file_put_contents($this->prompts, json_encode(['first prompt', 'second prompt']));
    }

    protected function tearDown(): void
    {
        @unlink($this->prompts);

        parent::tearDown();
    }

    public function test_it_runs_every_prompt_while_the_sampler_runs_and_prints_the_timeline_and_summary(): void
    {
        Ai::fake()->respondWithStream(['Late ', 'fees ', 'are ', 'charges.'], times: 2);

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts, '--interval' => 0.5])
            ->expectsOutputToContain('resident = a fixed test value')
            ->expectsOutputToContain('summary: load-to-first-token')
            ->assertSuccessful();

        $this->assertSame([['local', 0.5]], $this->sampler->started);
        $this->assertSame(1, $this->sampler->stopped);
        Ai::assertSentCount('profile', 2);
    }

    public function test_a_cold_model_is_reported_as_loading_and_a_warm_one_is_not(): void
    {
        Ai::fake()->respondWithStream(['ok'], times: 2);

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts])
            ->expectsOutputToContain('load-to-first-token')
            ->doesntExpectOutputToContain('already loaded')
            ->assertSuccessful();

        $this->app->instance(MemoryProbeFactory::class, new FixedMemoryProbeFactory(new FixedMemoryProbe(modelLoaded: true)));
        Ai::fake()->respondWithStream(['ok'], times: 2);

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts])
            ->expectsOutputToContain('n/a (model was already loaded)')
            ->assertSuccessful();
    }

    public function test_the_token_cap_is_sent_with_the_deterministic_sampling_values(): void
    {
        Ai::fake()->respondWithStream(['ok'], times: 2);

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts, '--max-tokens' => 64])->assertSuccessful();

        Ai::assertSent(fn ($request): bool => $request->feature === 'profile' && $request->options['max_tokens'] === 64 && $request->options['temperature'] === 0 && $request->options['seed'] === 42);
    }

    public function test_a_failing_prompt_stops_the_run_but_still_stops_the_sampler_and_reports(): void
    {
        Ai::fake()->failWith(new ProviderUnavailable('server down'));

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts])
            ->expectsOutputToContain('prompt 0: server down')
            ->assertFailed();

        $this->assertSame(1, $this->sampler->stopped);
        Ai::assertSentCount('profile', 1);
    }

    public function test_a_missing_prompt_file_is_reported_before_anything_starts(): void
    {
        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => '/nope/prompts.json'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();

        $this->assertSame([], $this->sampler->started);
    }

    public function test_an_unconfigured_provider_is_refused(): void
    {
        config()->set('ai.providers.mid.model', null);

        $this->artisan('ai:profile', ['--provider' => 'mid', '--prompts' => $this->prompts])
            ->expectsOutputToContain('not a configured chat provider')
            ->assertFailed();
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->artisan('ai:profile', ['--provider' => 'nope', '--prompts' => $this->prompts])
            ->expectsOutputToContain('nope')
            ->assertFailed();
    }

    public function test_without_a_known_bandwidth_the_summary_says_how_to_set_one(): void
    {
        $this->app->instance(HostInspector::class, FakeHost::macStudio(32, chip: 'Apple M9 Plus'));
        Ai::fake()->respondWithStream(['ok'], times: 2);

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts])
            ->expectsOutputToContain('AI_MEMORY_BANDWIDTH_GBPS')
            ->assertSuccessful();
    }

    public function test_a_bandwidth_option_sets_the_ceiling(): void
    {
        $this->app->instance(HostInspector::class, FakeHost::macStudio(32, chip: 'Apple M9 Plus'));
        Ai::fake()->respondWithStream(['ok'], times: 2);

        $this->artisan('ai:profile', ['--provider' => 'local', '--prompts' => $this->prompts, '--bandwidth-gbps' => 400])
            ->expectsOutputToContain('400 GB/s over 4.6 GiB of weights')
            ->assertSuccessful();
    }
}
