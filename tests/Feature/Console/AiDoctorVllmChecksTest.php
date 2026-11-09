<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeVllmServer;
use Tests\TestCase;

class AiDoctorVllmChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers.vllm.base_url', 'http://vllm.test:8000/v1');
        config()->set('ai.providers.vllm.api_key', FakeVllmServer::KEY);
        config()->set('ai.providers.vllm.license_reviewed', '2026-01-15');
        config()->set('ai.vllm.repository', 'neuralmagic/Meta-Llama-3.1-8B-Instruct-FP8');
        config()->set('ai.vllm.revision', '3f1a9c7d2b4e5a60718293a4b5c6d7e8f9012345');
        config()->set('ai.vllm.quantization', 'FP8');
        config()->set('ai.vllm.tool_parser', 'llama3_json');
        config()->set('ai.vllm.max_num_seqs', 32);
        config()->set('horizon.defaults.ai-interactive.processes', 24);
        config()->set('horizon.environments.testing.ai-interactive.processes', 24);
    }

    public function test_a_hardened_server_passes_every_check(): void
    {
        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✓ vLLM at http://vllm.test:8000 ok · version 0.32.0 · model ledgerly-support (FP8)')
            ->expectsOutputToContain('✓ revision pinned: 3f1a9c7d2b4e')
            ->expectsOutputToContain('✓ provider model ledgerly-support is served')
            ->expectsOutputToContain('✓ max-model-len 8192 >= budget window 8192 (an over-length request is a 400, mapped to ContextTooLong)')
            ->expectsOutputToContain('✓ KV cache 212,480 tokens · max concurrency ~26 at the full 8,192-token window')
            ->expectsOutputToContain('✓ ai queue processes 24 <= --max-num-seqs 32')
            ->expectsOutputToContain('✓ prefix caching on · 90% of prompt tokens served from cached blocks')
            ->expectsOutputToContain('✓ api key enforced: /v1/models without a key answers 401')
            ->expectsOutputToContain('✓ tool parser llama3_json declared')
            ->expectsOutputToContain('✓ /metrics reachable · 0 running, 0 waiting · KV cache 12% used')
            ->expectsOutputToContain('· 17 prompt tokens (10 cached) · 2 completion tokens')
            ->expectsOutputToContain('✓ schema-constrained completion answered [billing]')
            ->expectsOutputToContain('✓ license recorded and reviewed for all providers')
            ->expectsOutputToContain('0 warnings, 0 failures')
            ->assertSuccessful();
    }

    public function test_the_app_key_is_sent_as_a_bearer_token_to_the_authenticated_endpoints(): void
    {
        FakeVllmServer::healthy();

        $this->doctor()->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/models')
            && $request->header('Authorization') === ['Bearer '.FakeVllmServer::KEY]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/chat/completions')
            && $request->header('Authorization') === ['Bearer '.FakeVllmServer::KEY]);
    }

    public function test_a_server_that_is_not_running_fails_once_and_stops(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->doctor()
            ->expectsOutputToContain('✗ vLLM at http://vllm.test:8000 is not reachable. Is it running?')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_dead_engine_fails_with_the_503_from_health(): void
    {
        FakeVllmServer::healthy([
            'health' => Http::response('', 503),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ vLLM at http://vllm.test:8000 answered /health with 503: its engine process is dead.')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_wrong_api_key_fails_even_though_health_is_public(): void
    {
        FakeVllmServer::healthy([
            'models' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->doctor()
            ->expectsOutputToContain("✗ vLLM at http://vllm.test:8000 rejects this app's api key (HTTP 401 from /v1/models)")
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_model_alias_the_server_does_not_serve_fails_and_lists_what_it_does(): void
    {
        config()->set('ai.providers.vllm.model', 'support-v9');

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✗ provider model support-v9 is not served: /v1/models lists [ledgerly-support].')
            ->assertFailed();
    }

    public function test_a_max_model_len_below_the_budget_window_fails(): void
    {
        FakeVllmServer::healthy([
            'models' => Http::response(FakeVllmServer::models(maxModelLen: 4096)),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ max-model-len 4096 < budget window 8192: a prompt longer than 4096 tokens is refused with HTTP 400 (mapped to ContextTooLong).')
            ->assertFailed();
    }

    public function test_a_max_model_len_far_above_the_budget_window_is_a_warning(): void
    {
        FakeVllmServer::healthy([
            'models' => Http::response(FakeVllmServer::models(maxModelLen: 131072)),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ max-model-len 131072 is 16 times the budget window 8192')
            ->assertSuccessful();
    }

    public function test_a_feature_routed_to_a_loaded_adapter_passes_and_names_its_base(): void
    {
        $this->routeClassifyTo('vllm', 'ledgerly-classify-v2');

        FakeVllmServer::healthy([
            'models' => Http::response(FakeVllmServer::models([FakeVllmServer::adapter('ledgerly-classify-v2')])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✓ ticket.classify -> ledgerly-classify-v2 (LoRA adapter on ledgerly-support)')
            ->assertSuccessful();
    }

    public function test_a_feature_routed_to_an_adapter_the_server_has_not_loaded_fails(): void
    {
        $this->routeClassifyTo('vllm', 'ledgerly-classify-v3');

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✗ feature routed to a model this server does not serve: ticket.classify -> ledgerly-classify-v3.')
            ->assertFailed();
    }

    public function test_a_feature_routed_to_another_provider_is_not_judged_against_this_server(): void
    {
        $this->routeClassifyTo('local', 'something-else');

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('0 warnings, 0 failures')
            ->assertSuccessful();
    }

    public function test_a_cache_that_cannot_hold_one_full_window_sequence_fails(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response(FakeVllmServer::metrics(['cache_config' => [
                'kv_cache_max_concurrency' => '0.62',
                'kv_cache_size_tokens' => '5079',
            ]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ KV cache 5,079 tokens holds less than one full-window sequence of 8192 tokens.')
            ->assertFailed();
    }

    public function test_capacity_falls_back_to_blocks_times_block_size_when_the_tokens_label_is_missing(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response(FakeVllmServer::metrics(['cache_config' => [
                'kv_cache_max_concurrency' => 'None',
                'kv_cache_size_tokens' => 'None',
            ]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✓ KV cache 212,480 tokens · max concurrency ~26 at the full 8,192-token window')
            ->assertSuccessful();
    }

    public function test_a_metrics_page_without_any_capacity_label_is_a_warning(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response("# TYPE vllm:num_requests_running gauge\nvllm:num_requests_running{engine=\"0\"} 0.0\n"),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ vllm:cache_config_info carries neither kv_cache_size_tokens nor num_gpu_blocks')
            ->assertSuccessful();
    }

    public function test_more_queue_processes_than_max_num_seqs_is_a_warning(): void
    {
        config()->set('ai.vllm.max_num_seqs', 16);

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ ai queue processes (24) > --max-num-seqs (16): the extra workers wait inside vLLM.')
            ->assertSuccessful();
    }

    public function test_more_queue_processes_than_full_window_sequences_is_a_warning(): void
    {
        config()->set('horizon.environments.testing.ai-interactive.processes', 30);

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ ai queue processes (30) > the 25 full-window sequences the KV cache holds')
            ->assertSuccessful();
    }

    public function test_an_undeclared_max_num_seqs_says_how_to_declare_it(): void
    {
        config()->set('ai.vllm.max_num_seqs', null);

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✓ ai queue processes 24 (set VLLM_MAX_NUM_SEQS')
            ->assertSuccessful();
    }

    public function test_prefix_caching_off_is_a_warning(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response(FakeVllmServer::metrics(['cache_config' => ['enable_prefix_caching' => 'False']])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ prefix caching is off: every request pays prefill for the whole system prompt')
            ->assertSuccessful();
    }

    public function test_prefix_caching_that_the_metrics_do_not_state_is_a_warning(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response("vllm:cache_config_info{block_size=\"16\",kv_cache_size_tokens=\"212480\",kv_cache_max_concurrency=\"25.94\"} 1.0\n"),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ vllm:cache_config_info does not say whether prefix caching is on')
            ->assertSuccessful();
    }

    public function test_prefix_caching_with_no_traffic_yet_says_so(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response(FakeVllmServer::metrics(['series' => [
                'prefix_cache_queries_total' => 0,
                'prefix_cache_hits_total' => 0,
            ]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✓ prefix caching on (no requests counted yet)')
            ->assertSuccessful();
    }

    public function test_a_server_without_an_api_key_is_a_warning_on_loopback(): void
    {
        config()->set('ai.providers.vllm.base_url', 'http://127.0.0.1:8001/v1');

        FakeVllmServer::healthy([
            'models_without_key' => Http::response(FakeVllmServer::models()),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ vLLM was started without --api-key: any process on this machine can use it.')
            ->assertSuccessful();
    }

    public function test_a_server_without_an_api_key_is_a_failure_off_loopback(): void
    {
        FakeVllmServer::healthy([
            'models_without_key' => Http::response(FakeVllmServer::models()),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ vLLM on vllm.test answers without an api key: anyone who can reach it can run inference.')
            ->assertFailed();
    }

    public function test_an_undeclared_tool_parser_is_a_warning_that_says_what_goes_wrong(): void
    {
        config()->set('ai.vllm.tool_parser', null);

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('comes back as plain text in message.content with finish_reason stop')
            ->assertSuccessful();
    }

    public function test_metrics_that_answer_404_are_a_warning_and_skip_the_checks_that_need_them(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response('Not Found', 404),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ /metrics answered HTTP 404')
            ->doesntExpectOutputToContain('KV cache 212,480')
            ->doesntExpectOutputToContain('prefix caching')
            ->assertSuccessful();
    }

    public function test_preemptions_since_start_are_a_warning(): void
    {
        FakeVllmServer::healthy([
            'metrics' => Http::response(FakeVllmServer::metrics(['series' => ['num_preemptions_total' => 7]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ /metrics reachable · 7 preemptions since start: the KV cache filled and sequences were recomputed.')
            ->assertSuccessful();
    }

    public function test_lora_serving_shows_up_in_the_metrics_line(): void
    {
        $metrics = FakeVllmServer::metrics()."vllm:lora_requests_info{max_lora=\"4\",running_lora_adapters=\"\",waiting_lora_adapters=\"\"} 1.0\n";

        FakeVllmServer::healthy([
            'metrics' => Http::response($metrics),
        ]);

        $this->doctor()
            ->expectsOutputToContain('LoRA serving on (max 4 adapters per batch)')
            ->assertSuccessful();
    }

    public function test_a_round_trip_without_prompt_token_details_is_a_warning_naming_the_flag(): void
    {
        FakeVllmServer::healthy([
            'chat' => Http::response(FakeVllmServer::chatCompletion(['usage' => ['prompt_tokens' => 17, 'completion_tokens' => 2, 'total_tokens' => 19, 'prompt_tokens_details' => null]])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('start vLLM with --enable-prompt-tokens-details')
            ->assertSuccessful();
    }

    public function test_a_round_trip_without_usage_fails(): void
    {
        FakeVllmServer::healthy([
            'chat' => Http::response(['id' => 'x', 'choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /v1/chat/completions answered without usage')
            ->assertFailed();
    }

    public function test_a_failed_round_trip_fails_with_the_servers_message(): void
    {
        FakeVllmServer::healthy([
            'chat' => Http::response(['error' => ['message' => 'engine overloaded', 'type' => 'ServiceUnavailableError', 'code' => 503]], 503),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /v1/chat/completions round trip failed: HTTP 503: engine overloaded')
            ->assertFailed();
    }

    public function test_a_structured_answer_that_is_not_a_label_fails(): void
    {
        FakeVllmServer::healthy([
            'structured' => Http::response(FakeVllmServer::chatCompletion(['content' => 'I think this is about billing'])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ response_format answered [I think this is about billing], which is not a JSON object with one of the five labels')
            ->assertFailed();
    }

    public function test_a_structured_request_the_server_rejects_fails(): void
    {
        FakeVllmServer::healthy([
            'structured' => Http::response(['error' => ['message' => 'unsupported response_format', 'type' => 'BadRequestError', 'code' => 400]], 400),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ structured output request failed: HTTP 400: unsupported response_format')
            ->assertFailed();
    }

    public function test_the_structured_probe_sends_the_label_schema(): void
    {
        FakeVllmServer::healthy();

        $this->doctor()->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => isset($request['response_format'])
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['schema']['properties']['category']['enum'] === ['billing', 'howto', 'bug', 'account', 'other']);
    }

    public function test_an_undeclared_revision_is_a_warning(): void
    {
        config()->set('ai.vllm.revision', null);

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ VLLM_REVISION is not declared for this app')
            ->assertSuccessful();
    }

    public function test_a_revision_that_is_a_branch_name_is_a_warning(): void
    {
        config()->set('ai.vllm.revision', 'main');

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ revision main is not a 40-character commit sha')
            ->assertSuccessful();
    }

    public function test_a_provider_without_a_license_fails(): void
    {
        config()->set('ai.providers.vllm.license', null);

        FakeVllmServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✗ no license recorded for provider vllm')
            ->assertFailed();
    }

    public function test_the_ai_vllm_flag_points_the_doctor_at_a_compatible_provider(): void
    {
        config()->set('ai.providers.local.vllm', true);
        config()->set('ai.providers.local.base_url', 'http://vllm.test:8000/v1');
        config()->set('ai.providers.local.api_key', FakeVllmServer::KEY);
        config()->set('ai.providers.local.model', 'ledgerly-support');

        FakeVllmServer::healthy();

        $this->artisan('ai:doctor', ['--provider' => 'local'])
            ->expectsOutputToContain('vLLM at http://vllm.test:8000')
            ->assertSuccessful();
    }

    public function test_a_provider_that_is_none_of_the_three_servers_is_told_so(): void
    {
        $this->artisan('ai:doctor', ['--provider' => 'rerank-lexical'])
            ->expectsOutputToContain('does not point at an Ollama server, a llama-server or a vLLM server')
            ->assertFailed();
    }

    private function routeClassifyTo(string $provider, string $model): void
    {
        config()->set('ai.features', [
            ...config('ai.features'),
            'ticket.classify' => [
                'provider' => $provider,
                'model' => $model,
                'profile' => 'deterministic',
            ],
        ]);
    }

    private function doctor(array $options = []): PendingCommand
    {
        return $this->artisan('ai:doctor', ['--provider' => 'vllm', ...$options]);
    }
}
