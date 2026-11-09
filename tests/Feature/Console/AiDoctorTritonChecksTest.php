<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeTritonServer;
use Tests\TestCase;

class AiDoctorTritonChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers.triton.base_url', 'http://triton.test:9000/v1');
        config()->set('ai.providers.triton.kserve_url', 'http://kserve.test:8000');
        config()->set('ai.providers.triton.api_key', FakeTritonServer::KEY);
        config()->set('ai.providers.triton.license_reviewed', '2026-01-15');
        config()->set('ai.triton.image_tag', '26.10-vllm-python-py3');
        config()->set('ai.triton.tool_parser', 'llama3');
        config()->set('ai.triton.models', ['chat', 'reranker']);
    }

    public function test_a_hardened_server_passes_every_check(): void
    {
        FakeTritonServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('✓ Triton OpenAI frontend at http://triton.test:9000 ready')
            ->expectsOutputToContain('✓ KServe live and ready · server triton 2.74.0')
            ->expectsOutputToContain('✓ image tag pinned: 26.10-vllm-python-py3')
            ->expectsOutputToContain('✓ provider model chat is served by the OpenAI frontend')
            ->expectsOutputToContain('✓ model chat READY (version 1)')
            ->expectsOutputToContain('✓ model chat: backend vllm · max_batch_size 0 · 1 instance')
            ->expectsOutputToContain('✓ model reranker: backend onnxruntime · max_batch_size 64 · 2 instances · dynamic batching (2 ms window)')
            ->expectsOutputToContain('✓ the x-api-key header is enforced on /v1')
            ->expectsOutputToContain('✓ tool parser declared: llama3')
            ->expectsOutputToContain('✓ response_format is not declared as a capability')
            ->expectsOutputToContain('✓ model reranker: 48213 ok · 0 failed · average batch 15.5 · average queue 1.9 ms')
            ->expectsOutputToContain('✓ GPU 71% utilized · memory 75% used')
            ->expectsOutputToContain('no prompt_tokens_details, so cached tokens are estimated locally')
            ->expectsOutputToContain('✓ license recorded and reviewed for all providers')
            ->expectsOutputToContain('0 warnings, 0 failures')
            ->assertSuccessful();
    }

    public function test_the_configured_header_carries_the_key_to_the_authenticated_endpoints(): void
    {
        FakeTritonServer::healthy();

        $this->doctor()->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/models')
            && $request->header('x-api-key') === [FakeTritonServer::KEY]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/chat/completions')
            && $request->header('x-api-key') === [FakeTritonServer::KEY]
            && ! $request->hasHeader('Authorization'));
    }

    public function test_a_frontend_that_is_not_running_fails_once_and_stops(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->doctor()
            ->expectsOutputToContain('✗ Triton OpenAI frontend at http://triton.test:9000 is not reachable. Is it running?')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_model_that_is_still_loading_fails_the_ready_check(): void
    {
        FakeTritonServer::healthy([
            'ready' => Http::response(['detail' => 'Attached inference engine is not ready for inference requests.'], 400),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ Triton OpenAI frontend at http://triton.test:9000 answered /health/ready with 400')
            ->expectsOutputToContain('0 warnings, 1 failure')
            ->assertFailed();
    }

    public function test_a_wrong_key_fails_even_though_health_is_open(): void
    {
        FakeTritonServer::healthy([
            'models' => Http::response(FakeTritonServer::unauthorized(), 401),
        ]);

        $this->doctor()
            ->expectsOutputToContain("rejects this app's key (HTTP 401 from /v1/models)")
            ->assertFailed();
    }

    public function test_a_frontend_with_no_restriction_is_called_out(): void
    {
        FakeTritonServer::healthy([
            'models_without_key' => Http::response(FakeTritonServer::models()),
        ]);

        $this->doctor()
            ->expectsOutputToContain('/v1/models answered without the x-api-key header: the OpenAI frontend has no authentication unless it is started with --openai-restricted-api')
            ->assertSuccessful();
    }

    public function test_the_alias_must_be_a_model_the_frontend_lists(): void
    {
        FakeTritonServer::healthy([
            'models' => Http::response(FakeTritonServer::models(['other'])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ provider model chat is not served: /v1/models lists [other].')
            ->assertFailed();
    }

    public function test_the_kserve_checks_are_skipped_with_a_warning_when_no_url_is_set(): void
    {
        config()->set('ai.providers.triton.kserve_url', null);

        FakeTritonServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('⚠ TRITON_KSERVE_URL is not set')
            ->assertSuccessful();
    }

    public function test_a_model_that_failed_to_load_fails_with_its_reason(): void
    {
        FakeTritonServer::healthy([
            'index' => Http::response([
                ['name' => 'chat', 'version' => '1', 'state' => 'READY'],
                ['name' => 'reranker', 'version' => '1', 'state' => 'UNAVAILABLE', 'reason' => 'failed to load model.onnx'],
            ]),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ model reranker is UNAVAILABLE: failed to load model.onnx')
            ->assertFailed();
    }

    public function test_a_declared_model_missing_from_the_repository_fails(): void
    {
        FakeTritonServer::healthy([
            'index' => Http::response([
                ['name' => 'chat', 'version' => '1', 'state' => 'READY'],
            ]),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ model reranker is not in the repository index')
            ->assertFailed();
    }

    public function test_a_batching_model_without_dynamic_batching_and_a_model_without_warmup_warn(): void
    {
        $configs = FakeTritonServer::configs();
        unset($configs['reranker']['dynamic_batching'], $configs['reranker']['model_warmup']);

        FakeTritonServer::healthy([
            'configs' => $configs,
            'index' => Http::response([
                ['name' => 'chat', 'version' => '1', 'state' => 'READY'],
                ['name' => 'reranker', 'version' => '1', 'state' => 'READY'],
            ]),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ model reranker takes batches but declares no dynamic_batching')
            ->expectsOutputToContain('⚠ model reranker declares no model_warmup')
            ->assertSuccessful();
    }

    public function test_the_chat_model_is_not_asked_for_a_warmup_block_because_ai_warm_covers_it(): void
    {
        FakeTritonServer::healthy();

        $this->doctor()
            ->doesntExpectOutputToContain('model chat declares no model_warmup')
            ->assertSuccessful();
    }

    public function test_without_a_declared_tool_parser_the_doctor_says_what_the_failure_looks_like(): void
    {
        config()->set('ai.triton.tool_parser', null);

        FakeTritonServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('requires --tool-call-parser to be set')
            ->assertSuccessful();
    }

    public function test_declaring_structured_output_on_the_provider_fails_because_the_frontend_drops_response_format(): void
    {
        config()->set('ai.providers.triton.capabilities', ['tools', 'seed', 'structured']);

        FakeTritonServer::healthy();

        $this->doctor()
            ->expectsOutputToContain('declares the structured capability, but the Triton OpenAI frontend does not apply response_format')
            ->assertFailed();
    }

    public function test_failed_requests_and_an_undeclared_image_tag_warn(): void
    {
        config()->set('ai.triton.image_tag', null);

        FakeTritonServer::healthy([
            'metrics' => Http::response(FakeTritonServer::metrics([
                'nv_inference_request_failure{model="reranker",version="1"}' => 7,
            ])),
        ]);

        $this->doctor()
            ->expectsOutputToContain('⚠ TRITON_TAG is not declared')
            ->expectsOutputToContain('⚠ model reranker: 48213 ok · 7 failed')
            ->assertSuccessful();
    }

    public function test_a_completion_without_usage_fails(): void
    {
        FakeTritonServer::healthy([
            'chat' => Http::response(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']]]),
        ]);

        $this->doctor()
            ->expectsOutputToContain('✗ /v1/chat/completions answered without usage')
            ->assertFailed();
    }

    public function test_the_ai_triton_flag_points_the_doctor_at_a_compatible_provider(): void
    {
        config()->set('ai.providers.local.triton', true);
        config()->set('ai.providers.local.base_url', 'http://triton.test:9000/v1');
        config()->set('ai.providers.local.api_key', FakeTritonServer::KEY);
        config()->set('ai.providers.local.auth_header', 'x-api-key');
        config()->set('ai.providers.local.model', 'chat');

        FakeTritonServer::healthy();

        $this->artisan('ai:doctor', ['--provider' => 'local'])
            ->expectsOutputToContain('Triton OpenAI frontend at http://triton.test:9000')
            ->assertSuccessful();
    }

    private function doctor(array $options = []): PendingCommand
    {
        return $this->artisan('ai:doctor', ['--provider' => 'triton', ...$options]);
    }
}
