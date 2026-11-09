<?php

namespace Tests\Feature\Console;

use App\Ai\Doctor\BindProbe;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeBindProbe;
use Tests\Support\FakeOllama;
use Tests\TestCase;

class AiDoctorOllamaChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers.local.base_url', 'http://ollama.test:11434/v1');
        config()->set('ai.providers.local.model', 'ledgerly-support');
        config()->set('ai.providers.local.license_reviewed', '2026-01-15');
        config()->set('ai.providers.local-embed.base_url', 'http://ollama.test:11434/v1');
        config()->set('ai.providers.local-embed.license_reviewed', '2026-01-15');
        config()->set('ai.ollama.providers', ['local', 'local-embed']);
        config()->set('ai.ollama.native', false);
        config()->set('ai.ollama.num_ctx', null);
        config()->set('ai.ollama.num_parallel', 2);
        config()->set('horizon.defaults.ai-interactive.processes', 2);
        config()->set('horizon.environments.testing.ai-interactive.processes', 2);

        $this->app->instance(BindProbe::class, new FakeBindProbe(resolved: ['ollama.test' => '10.0.0.5']));
    }

    public function test_a_hardened_setup_passes_every_check(): void
    {
        FakeOllama::healthy();

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✓ Ollama at http://ollama.test:11434 reachable; version 0.40.15')
            ->expectsOutputToContain('✓ models present: ledgerly-support (Q4_K_M, 8.0B, 46e0c10c039e), nomic-embed-text (F16, 137M, 46e0c10c039e)')
            ->expectsOutputToContain('✓ ledgerly-support num_ctx 8192 (loaded, as served to /v1) >= budget window 8192')
            ->expectsOutputToContain('✓ ledgerly-support:latest loaded: 100% GPU (5.4 GiB)')
            ->expectsOutputToContain('✓ OLLAMA_NUM_PARALLEL=2 (declared) matches ai queue processes (2)')
            ->expectsOutputToContain('✓ server keep-alive is not the 5m default')
            ->expectsOutputToContain('✓ ollama.test:11434 resolves to a private address (10.0.0.5)')
            ->expectsOutputToContain('with exact counts (17 prompt, 2 generated)')
            ->expectsOutputToContain('✓ license recorded and reviewed for all providers')
            ->expectsOutputToContain('0 warnings, 0 failures')
            ->assertSuccessful();
    }

    public function test_a_modelfile_that_pins_4096_fails_the_check_that_breaks_a_prompt_silently(): void
    {
        FakeOllama::healthy([
            'running_after_compatible' => [FakeOllama::running('ledgerly-support:latest', contextLength: 4096, minutesLeft: 30)],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ ledgerly-support num_ctx 4096 (loaded, as served to /v1) < budget window 8192: prompts will be silently truncated. Set num_ctx in the Modelfile.')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_the_window_option_changes_what_the_context_is_compared_with(): void
    {
        FakeOllama::healthy();

        $this->artisan('ai:doctor', ['--ctx' => 16384])
            ->expectsOutputToContain('< budget window 16384')
            ->assertFailed();
    }

    public function test_the_server_default_context_is_read_from_the_loaded_model_and_warned_about_when_it_dwarfs_the_budget(): void
    {
        FakeOllama::healthy([
            'running_after_compatible' => [FakeOllama::running('ledgerly-support:latest', contextLength: 32768, minutesLeft: 30)],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ ledgerly-support num_ctx 32768 (loaded, as served to /v1) is 4 times the budget window 8192: the KV cache is allocated for all of it when the model loads. Pin num_ctx 8192 in the Modelfile.')
            ->expectsOutputToContain('1 warning, 0 failures')
            ->assertSuccessful();
    }

    public function test_when_the_model_is_not_loaded_the_modelfile_value_is_used_and_without_one_the_context_is_unknown(): void
    {
        FakeOllama::healthy([
            'models' => [
                'ledgerly-support:latest' => FakeOllama::model('ledgerly-support:latest', parameters: 'temperature 0.7'),
                'nomic-embed-text:latest' => FakeOllama::model('nomic-embed-text:latest', quantization: 'F16', size: '137M'),
            ],
            'running_after_compatible' => [],
            'running_after_native' => [],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ ledgerly-support num_ctx unknown: not set in its Modelfile and the model is not loaded')
            ->assertSuccessful();
    }

    public function test_a_model_configured_but_not_pulled_fails_with_the_command_that_fixes_it(): void
    {
        FakeOllama::healthy(['models' => [
            'nomic-embed-text:latest' => FakeOllama::model('nomic-embed-text:latest', quantization: 'F16', size: '137M'),
        ]]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ model missing for provider local (ledgerly-support): ollama pull ledgerly-support')
            ->assertFailed();
    }

    public function test_a_model_that_spilled_to_the_cpu_is_a_warning_with_the_split(): void
    {
        FakeOllama::healthy([
            'running_after_native' => [FakeOllama::running('ledgerly-support:latest', contextLength: 8192, minutesLeft: 30, gpuShare: 0.71)],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ ledgerly-support:latest loaded: 71%/29% GPU/CPU (5.4 GiB)')
            ->assertSuccessful();
    }

    public function test_placement_is_a_warning_when_nothing_is_loaded_to_read(): void
    {
        FakeOllama::healthy(['running_after_native' => [], 'running_after_compatible' => []]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ no configured model is listed by /api/ps after the round trip (loaded: nothing)')
            ->assertSuccessful();
    }

    public function test_a_model_served_by_another_aliass_runner_is_named_in_the_warnings(): void
    {
        FakeOllama::healthy([
            'running_after_compatible' => [FakeOllama::running('llama3.1:8b', contextLength: 8192, minutesLeft: 30)],
            'running_after_native' => [FakeOllama::running('llama3.1:8b', contextLength: 8192, minutesLeft: 30)],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ no configured model is listed by /api/ps after the round trip (loaded: llama3.1:8b)')
            ->expectsOutputToContain('⚠ keep-alive could not be read: ledgerly-support is not listed by /api/ps after the /v1 round trip (loaded: llama3.1:8b)')
            ->expectsOutputToContain('ledgerly-support num_ctx 8192 (Modelfile) >= budget window 8192')
            ->assertSuccessful();
    }

    public function test_parallel_slots_are_compared_with_the_ai_queue_workers(): void
    {
        FakeOllama::healthy();
        config()->set('ai.ollama.num_parallel', 1);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ OLLAMA_NUM_PARALLEL=1 (declared) < ai queue processes (2): the extra workers just queue inside Ollama.')
            ->assertSuccessful();
    }

    public function test_more_slots_than_workers_waste_a_kv_cache_each(): void
    {
        FakeOllama::healthy();
        config()->set('ai.ollama.num_parallel', 4);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('> ai queue processes (2): each slot holds a KV cache that nothing fills.')
            ->assertSuccessful();
    }

    public function test_undeclared_parallel_slots_cannot_be_checked_and_say_so(): void
    {
        FakeOllama::healthy();
        config()->set('ai.ollama.num_parallel', null);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ OLLAMA_NUM_PARALLEL is not declared for this app, and the server does not report it')
            ->assertSuccessful();
    }

    public function test_the_five_minute_keep_alive_default_is_seen_from_the_loaded_models_expiry(): void
    {
        FakeOllama::healthy([
            'running_after_compatible' => [FakeOllama::running('ledgerly-support:latest', contextLength: 8192, minutesLeft: 5)],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ server keep-alive looks like the 5m default')
            ->assertSuccessful();
    }

    public function test_when_the_app_sends_keep_alive_itself_the_server_default_only_matters_for_other_calls(): void
    {
        FakeOllama::healthy([
            'running_after_compatible' => [FakeOllama::running('ledgerly-support:latest', contextLength: 8192, minutesLeft: 5)],
        ]);
        config()->set('ai.ollama.native', true);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✓ chat requests carry keep_alive 30m; other /v1 calls (embeddings) still unload after the server default of 5m')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/chat') && $request['keep_alive'] === '30m');
    }

    public function test_a_model_pinned_forever_is_not_the_default(): void
    {
        FakeOllama::healthy([
            'running_after_compatible' => [FakeOllama::running('ledgerly-support:latest', contextLength: 8192, minutesLeft: 60 * 24 * 365 * 200)],
        ]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✓ server keep-alive is not the 5m default')
            ->assertSuccessful();
    }

    public function test_a_public_address_that_answers_on_the_port_fails(): void
    {
        FakeOllama::healthy();
        config()->set('ai.providers.local.base_url', 'http://localhost:11434/v1');
        config()->set('ai.providers.local-embed.base_url', 'http://localhost:11434/v1');
        $this->app->instance(BindProbe::class, new FakeBindProbe(['127.0.0.1', '93.184.216.34'], accepting: ['93.184.216.34']));

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ port 11434 answers on the public address 93.184.216.34')
            ->assertFailed();
    }

    public function test_a_private_address_that_answers_on_the_port_is_a_warning(): void
    {
        FakeOllama::healthy();
        config()->set('ai.providers.local.base_url', 'http://localhost:11434/v1');
        config()->set('ai.providers.local-embed.base_url', 'http://localhost:11434/v1');
        $this->app->instance(BindProbe::class, new FakeBindProbe(['127.0.0.1', '192.168.1.20'], accepting: ['192.168.1.20']));

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ port 11434 answers on 192.168.1.20, so the whole local network can use it')
            ->assertSuccessful();
    }

    public function test_loopback_only_binding_passes(): void
    {
        FakeOllama::healthy();
        config()->set('ai.providers.local.base_url', 'http://localhost:11434/v1');
        config()->set('ai.providers.local-embed.base_url', 'http://localhost:11434/v1');
        $this->app->instance(BindProbe::class, new FakeBindProbe(['127.0.0.1', '192.168.1.20']));

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✓ port 11434 not bound on a public interface (loopback only)')
            ->assertSuccessful();
    }

    public function test_a_server_at_a_public_address_fails(): void
    {
        FakeOllama::healthy();
        $this->app->instance(BindProbe::class, new FakeBindProbe);
        config()->set('ai.providers.local.base_url', 'http://93.184.216.35:11434/v1');
        config()->set('ai.providers.local-embed.base_url', 'http://93.184.216.35:11434/v1');

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ 93.184.216.35:11434 resolves to the public address 93.184.216.35')
            ->assertFailed();
    }

    public function test_a_host_that_cannot_be_resolved_is_a_warning_not_a_guess(): void
    {
        FakeOllama::healthy();
        $this->app->instance(BindProbe::class, new FakeBindProbe);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ could not resolve ollama.test')
            ->assertSuccessful();
    }

    public function test_a_failing_compatible_round_trip_fails(): void
    {
        FakeOllama::healthy(['compatible' => Http::response(['error' => 'boom'], 500)]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ /v1/chat/completions round trip failed: HTTP 500')
            ->assertFailed();
    }

    public function test_a_native_answer_without_counts_fails_because_model_calls_would_not_be_exact(): void
    {
        FakeOllama::healthy(['native' => Http::response(['message' => ['role' => 'assistant', 'content' => 'ok'], 'done' => true])]);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ /api/chat answered without prompt_eval_count and eval_count')
            ->assertFailed();
    }

    public function test_a_provider_without_a_recorded_license_fails_and_an_unreviewed_one_warns(): void
    {
        FakeOllama::healthy();
        config()->set('ai.providers.local.license', null);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ no license recorded for provider local')
            ->assertFailed();

        config()->set('ai.providers.local.license', 'Llama 3.1 Community License');
        config()->set('ai.providers.local-embed.license_reviewed', null);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ license recorded for all providers, not yet reviewed for local-embed')
            ->assertSuccessful();
    }

    public function test_native_requests_asking_for_a_different_context_than_v1_gets_are_a_reload_warning(): void
    {
        FakeOllama::healthy();
        config()->set('ai.ollama.num_ctx', 16384);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('⚠ native requests ask for num_ctx 16384 but /v1 traffic is served at 8192: Ollama reloads the model whenever the context changes between requests.')
            ->assertSuccessful();
    }

    public function test_a_configured_native_context_below_the_window_fails(): void
    {
        FakeOllama::healthy();
        config()->set('ai.ollama.num_ctx', 4096);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ AI_NUM_CTX 4096 < budget window 8192')
            ->assertFailed();
    }

    public function test_an_unreachable_server_stops_after_the_first_check(): void
    {
        FakeOllama::healthy(['version' => '']);

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✗ Ollama at http://ollama.test:11434 is not reachable. Is it running?')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();

        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/tags'));
    }

    public function test_a_provider_on_another_server_is_left_out_of_the_checks(): void
    {
        FakeOllama::healthy();
        config()->set('ai.providers.local-embed.base_url', 'http://elsewhere.test:11434/v1');

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('✓ models present: ledgerly-support (Q4_K_M, 8.0B, 46e0c10c039e)')
            ->assertSuccessful();
    }

    public function test_a_provider_that_is_not_ollama_is_refused(): void
    {
        config()->set('ai.providers.local.base_url', 'https://api.openai.com/v1');
        Http::fake();

        $this->artisan('ai:doctor')
            ->expectsOutputToContain('does not point at an Ollama server')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_unknown_provider_is_reported(): void
    {
        $this->artisan('ai:doctor', ['--provider' => 'nope'])
            ->expectsOutputToContain('nope')
            ->assertFailed();
    }

    public function test_the_provider_option_picks_the_server_and_model_to_probe(): void
    {
        FakeOllama::healthy();
        config()->set('ai.providers.local-70b.base_url', 'http://ollama.test:11434/v1');
        config()->set('ai.providers.local-70b.model', 'ledgerly-support');

        $this->artisan('ai:doctor', ['--provider' => 'local-70b'])
            ->expectsOutputToContain('✓ Ollama at http://ollama.test:11434 reachable')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/chat/completions') && $request['model'] === 'ledgerly-support');
    }
}
