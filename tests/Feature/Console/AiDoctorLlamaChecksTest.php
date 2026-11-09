<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeLlamaServer;
use Tests\TestCase;

class AiDoctorLlamaChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers.local-llama.base_url', 'http://llama.test:8080/v1');
        config()->set('ai.providers.local-llama.api_key', FakeLlamaServer::KEY);
        config()->set('ai.providers.local-llama.license_reviewed', '2026-01-15');
        config()->set('ai.token_counter', 'llama-server');
        config()->set('ai.llama.gpu_layers', '99');
        config()->set('ai.llama.model_layers', 33);
        config()->set('ai.llama.backend', 'gpu');
        config()->set('horizon.defaults.ai-interactive.processes', 2);
        config()->set('horizon.environments.testing.ai-interactive.processes', 2);
    }

    public function test_a_hardened_server_passes_every_check(): void
    {
        FakeLlamaServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✓ llama-server at http://llama.test:8080 ok · build b11988-3a1f2c4 · model ledgerly-support (Q4_K - Medium)')
            ->expectsOutputToContain('✓ slots 2 = ai queue processes 2')
            ->expectsOutputToContain('✓ context per slot 8192 >= budget window 8192')
            ->expectsOutputToContain('✓ layers offloaded 33/33 (declared -ngl 99)')
            ->expectsOutputToContain('✓ /metrics reachable · prefix cache reused 90% of prompt tokens · longest sequence 2410 of 8192 tokens')
            ->expectsOutputToContain('✓ api key enforced: /props without a key answers 401')
            ->expectsOutputToContain('✓ /tokenize exact counter active (10 tokens for the sample)')
            ->expectsOutputToContain('· 17 prompt tokens (10 reused from the cache) at 546.9 tok/s · decode 52.9 tok/s')
            ->expectsOutputToContain('✓ grammar-constrained /completion answered [billing]')
            ->expectsOutputToContain('✓ license recorded and reviewed for all providers')
            ->expectsOutputToContain('0 warnings, 0 failures')
            ->assertSuccessful();
    }

    public function test_the_app_key_is_sent_as_a_bearer_token_to_every_authenticated_endpoint(): void
    {
        FakeLlamaServer::healthy();

        $this->doctor()->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/tokenize')
            && $request->header('Authorization') === ['Bearer '.FakeLlamaServer::KEY]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/metrics')
            && $request->header('Authorization') === ['Bearer '.FakeLlamaServer::KEY]);
    }

    public function test_a_server_that_is_not_running_fails_once_and_stops(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->doctor()
            ->expectsOutputToContain('✗ llama-server at http://llama.test:8080 is not reachable. Is it running?')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_server_still_loading_its_model_fails_with_the_503(): void
    {
        FakeLlamaServer::healthy([
            'health' => Http::response(['error' => ['code' => 503, 'message' => 'Loading model', 'type' => 'unavailable_error']], 503),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ llama-server at http://llama.test:8080 is still loading the model (HTTP 503 from /health)')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_wrong_api_key_fails_even_though_health_is_public(): void
    {
        FakeLlamaServer::healthy([
            'props' => Http::response(['error' => ['code' => 401, 'message' => 'Invalid API Key', 'type' => 'authentication_error']], 401),
        ]);

        $this->doctor()
            ->expectsOutputToContain("✗ llama-server at http://llama.test:8080 rejects this app's api key (HTTP 401 from /props)")
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_fewer_slots_than_queue_workers_is_a_warning(): void
    {
        FakeLlamaServer::healthy([
            'props' => Http::response(FakeLlamaServer::props(['total_slots' => 1])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ slots 1 < ai queue processes (2): the extra workers just wait for a free slot inside llama-server.')
            ->assertSuccessful();
    }

    public function test_more_slots_than_queue_workers_is_a_warning(): void
    {
        FakeLlamaServer::healthy([
            'props' => Http::response(FakeLlamaServer::props(['total_slots' => 4])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ slots 4 > ai queue processes (2): each slot reserves its own context, and nothing fills the extra ones.')
            ->assertSuccessful();
    }

    public function test_a_slot_smaller_than_the_budget_window_fails(): void
    {
        FakeLlamaServer::healthy([
            'props' => Http::response(FakeLlamaServer::props(['default_generation_settings' => ['n_ctx' => 4096]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ context per slot 4096 < budget window 8192: a prompt longer than 4096 tokens is refused by llama-server (HTTP 400, exceed_context_size_error).')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_slot_far_larger_than_the_budget_window_is_a_warning(): void
    {
        FakeLlamaServer::healthy([
            'props' => Http::response(FakeLlamaServer::props(['default_generation_settings' => ['n_ctx' => 32768]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ context per slot 32768 is 4 times the budget window 8192')
            ->assertSuccessful();
    }

    public function test_the_window_option_changes_what_the_slot_is_compared_with(): void
    {
        FakeLlamaServer::healthy();

        $this->doctor(['--ctx' => 16384])
            ->expectsOutputToContain('✗ context per slot 8192 < budget window 16384')
            ->assertFailed();
    }

    public function test_an_undeclared_gpu_layer_count_is_a_warning_that_says_why_it_cannot_be_read(): void
    {
        config()->set('ai.llama.gpu_layers', null);

        FakeLlamaServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ LLAMA_NGL is not declared for this app, and llama-server does not report how many layers it offloaded')
            ->assertSuccessful();
    }

    public function test_fewer_layers_offloaded_than_the_model_has_is_a_warning(): void
    {
        config()->set('ai.llama.gpu_layers', '20');

        FakeLlamaServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ layers offloaded 20/33 (declared -ngl 20): the rest run on the CPU')
            ->assertSuccessful();
    }

    public function test_all_and_auto_and_zero_are_understood(): void
    {
        FakeLlamaServer::healthy();

        config()->set('ai.llama.gpu_layers', 'all');
        $this->doctor()->expectsOutputToContain('✓ -ngl all declared: every layer is offloaded')->assertSuccessful();

        config()->set('ai.llama.gpu_layers', 'auto');
        $this->doctor()->expectsOutputToContain('⚠ -ngl auto declared')->assertSuccessful();

        config()->set('ai.llama.gpu_layers', '0');
        $this->doctor()->expectsOutputToContain('⚠ -ngl 0 declared on a GPU profile')->assertSuccessful();
    }

    public function test_the_cpu_profile_does_not_expect_offload(): void
    {
        config()->set('ai.llama.backend', 'cpu');
        config()->set('ai.llama.gpu_layers', null);

        FakeLlamaServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✓ CPU profile: no layers are offloaded by design (-ngl 0)')
            ->assertSuccessful();
    }

    public function test_metrics_that_were_never_switched_on_are_a_warning(): void
    {
        FakeLlamaServer::healthy([
            'metrics' => Http::response(['error' => ['code' => 501, 'message' => 'This server does not support metrics endpoint. Start it with `--metrics`', 'type' => 'not_supported_error']], 501),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ /metrics is off: llama-server was started without --metrics')
            ->assertSuccessful();
    }

    public function test_speculative_decoding_shows_up_in_the_metrics_line(): void
    {
        FakeLlamaServer::healthy([
            'metrics' => Http::response(FakeLlamaServer::metrics(drafted: 200, accepted: 150)),
        ]);

        $this->doctor()
            ->expectsOutputToContain('speculative decoding accepted 75% of drafted tokens')
            ->assertSuccessful();
    }

    public function test_a_server_without_an_api_key_on_loopback_is_a_warning(): void
    {
        config()->set('ai.providers.local-llama.base_url', 'http://127.0.0.1:8080/v1');

        FakeLlamaServer::healthy([
            'props_without_key' => Http::response(FakeLlamaServer::props()),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ llama-server was started without --api-key: any process on this machine can use it.')
            ->assertSuccessful();
    }

    public function test_a_server_without_an_api_key_on_the_network_is_a_failure(): void
    {
        FakeLlamaServer::healthy([
            'props_without_key' => Http::response(FakeLlamaServer::props()),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ llama-server on llama.test answers without an api key')
            ->assertFailed();
    }

    public function test_the_estimating_counter_is_a_warning_even_though_tokenize_works(): void
    {
        config()->set('ai.token_counter', 'ratio');

        FakeLlamaServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ /tokenize works (10 tokens for the sample) but the app counts with [ratio]: set AI_TOKEN_COUNTER=llama-server')
            ->assertSuccessful();
    }

    public function test_a_tokenize_endpoint_that_fails_is_a_failure(): void
    {
        FakeLlamaServer::healthy([
            'tokenize' => Http::response(['error' => ['code' => 500, 'message' => 'boom', 'type' => 'server_error']], 500),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /tokenize failed: HTTP 500: boom')
            ->assertFailed();
    }

    public function test_a_round_trip_without_usage_fails_because_model_calls_would_have_no_counts(): void
    {
        FakeLlamaServer::healthy([
            'chat' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /v1/chat/completions answered without usage')
            ->assertFailed();
    }

    public function test_a_round_trip_without_timings_is_a_warning(): void
    {
        FakeLlamaServer::healthy([
            'chat' => Http::response(array_diff_key(FakeLlamaServer::chatCompletion(), ['timings' => true])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('with exact counts, but no timings object')
            ->assertSuccessful();
    }

    public function test_a_failed_round_trip_is_a_failure(): void
    {
        FakeLlamaServer::healthy([
            'chat' => Http::response(['error' => ['code' => 500, 'message' => 'slot error', 'type' => 'server_error']], 500),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /v1/chat/completions round trip failed: HTTP 500: slot error')
            ->assertFailed();
    }

    public function test_a_build_without_apply_template_fails_the_grammar_check(): void
    {
        FakeLlamaServer::healthy([
            'apply_template' => Http::response(['error' => ['code' => 404, 'message' => 'File Not Found', 'type' => 'not_found_error']], 404),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /apply-template failed: HTTP 404: File Not Found.')
            ->assertFailed();
    }

    public function test_a_server_that_ignores_the_grammar_fails_the_grammar_check(): void
    {
        FakeLlamaServer::healthy([
            'completion' => Http::response(['content' => 'I would say billing', 'stop_type' => 'eos']),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /completion with the label grammar answered [I would say billing]')
            ->assertFailed();
    }

    public function test_the_grammar_check_sends_the_label_grammar(): void
    {
        FakeLlamaServer::healthy();

        $this->doctor()->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/completion')
            && $request['grammar'] === 'root ::= "billing" | "howto" | "bug" | "account" | "other"');
    }

    public function test_an_unreviewed_license_is_a_warning_and_a_missing_one_a_failure(): void
    {
        FakeLlamaServer::healthy();

        config()->set('ai.providers.local-llama.license_reviewed', null);
        $this->doctor()->expectsOutputToContain('⚠ license recorded for all providers, not yet reviewed for local-llama')->assertSuccessful();

        config()->set('ai.providers.local-llama.license', '');
        $this->doctor()->expectsOutputToContain('✗ no license recorded for provider local-llama')->assertFailed();
    }

    public function test_a_provider_flagged_as_llama_is_checked_as_llama_server(): void
    {
        config()->set('ai.providers.local.llama', true);
        config()->set('ai.providers.local.base_url', 'http://llama.test:8080/v1');

        FakeLlamaServer::healthy();

        $this->artisan('ai:doctor', ['--provider' => 'local'])
            ->expectsOutputToContain('llama-server at http://llama.test:8080')
            ->assertSuccessful();
    }

    public function test_the_default_ollama_check_is_untouched_by_a_llama_server_provider_existing(): void
    {
        FakeLlamaServer::healthy();

        $this->artisan('ai:doctor', ['--provider' => 'rerank-lexical'])
            ->expectsOutputToContain('does not point at an Ollama server, a llama-server or a vLLM server')
            ->assertFailed();
    }

    private function doctor(array $options = []): PendingCommand
    {
        return $this->artisan('ai:doctor', ['--provider' => 'local-llama', ...$options]);
    }
}
