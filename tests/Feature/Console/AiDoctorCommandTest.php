<?php

namespace Tests\Feature\Console;

use App\Ai\Hardware\HostInspector;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHost;
use Tests\TestCase;

class AiDoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->app->instance(HostInspector::class, FakeHost::macStudio(128, chip: 'Apple M2 Ultra'));
    }

    public function test_a_70b_at_q4_fits_a_128_gib_mac_and_the_summary_shows_the_terms(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:70b-instruct-q4_K_M', '--ctx' => 8192, '--slots' => 2])
            ->expectsOutputToContain('weights 39.5 GiB (q4_k_m) · KV cache 5.0 GiB (f16; 2.7 GiB at q8_0)')
            ->expectsOutputToContain('reachable on this machine (Apple M2 Ultra): 96.0 GiB')
            ->expectsOutputToContain('✓ fits with')
            ->assertSuccessful();
    }

    public function test_a_model_that_does_not_fit_fails_and_lists_what_would_help(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:405b', '--ctx' => 8192])
            ->expectsOutputToContain('✗ does not fit: short by')
            ->expectsOutputToContain('drop to q3_k_m')
            ->assertFailed();
    }

    public function test_reachable_gib_stands_in_for_the_detected_memory(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:70b-instruct-q4_K_M', '--reachable-gib' => 24])
            ->expectsOutputToContain('reachable on this machine (Apple M2 Ultra): 24.0 GiB')
            ->expectsOutputToContain('✗ does not fit')
            ->assertFailed();
    }

    public function test_without_a_model_it_sizes_the_providers_configured_model(): void
    {
        $this->serverDown();

        config()->set('ai.providers.local.model', 'llama3.1:8b');

        $this->artisan('ai:doctor', ['--size' => true])
            ->expectsOutputToContain('weights 4.5 GiB')
            ->assertSuccessful();
    }

    public function test_a_model_the_app_cannot_size_names_the_options_to_pass(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'mystery:13b'])
            ->expectsOutputToContain('--layers=')
            ->assertFailed();
    }

    public function test_options_describe_an_unknown_model(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', [
            '--size' => true,
            '--model' => 'mystery:13b',
            '--parameters-billions' => 13,
            '--layers' => 40,
            '--kv-heads' => 8,
            '--head-dim' => 128,
        ])
            ->expectsOutputToContain('model from options')
            ->assertSuccessful();
    }

    public function test_an_unknown_provider_is_reported(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', ['--size' => true, '--provider' => 'nope'])
            ->expectsOutputToContain('nope')
            ->assertFailed();
    }

    public function test_an_undetected_machine_asks_for_the_reachable_memory(): void
    {
        $this->serverDown();

        $this->app->instance(HostInspector::class, new FakeHost('Windows'));

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:8b'])
            ->expectsOutputToContain('AI_REACHABLE_MEMORY_GIB')
            ->assertFailed();
    }

    public function test_without_size_it_runs_the_ollama_checks_and_stops_when_the_server_is_down(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('is not reachable')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_bad_cache_type_is_reported(): void
    {
        $this->serverDown();

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:8b', '--cache' => 'q2'])
            ->expectsOutputToContain('Unknown cache type [q2]')
            ->assertFailed();
    }

    public function test_the_server_is_asked_about_the_model_so_a_pulled_models_real_size_is_used(): void
    {
        Http::fake([
            '*/api/show' => Http::response(['details' => ['quantization_level' => 'Q4_K_M'], 'model_info' => [
                'general.architecture' => 'llama',
                'llama.block_count' => 32,
                'llama.attention.head_count' => 32,
                'llama.attention.head_count_kv' => 8,
                'llama.embedding_length' => 4096,
            ]]),
            '*/api/tags' => Http::response(['models' => [['name' => 'ledgerly-support:8b', 'size' => 10 * 1_073_741_824]]]),
        ]);

        $this->artisan('ai:doctor', ['--size' => true, '--model' => 'ledgerly-support:8b'])
            ->expectsOutputToContain('weights 10.0 GiB')
            ->expectsOutputToContain('model from server')
            ->assertSuccessful();
    }

    private function serverDown(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
    }
}
