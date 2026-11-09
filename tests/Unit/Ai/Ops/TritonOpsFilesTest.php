<?php

namespace Tests\Unit\Ai\Ops;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TritonOpsFilesTest extends TestCase
{
    public function test_the_compose_service_is_a_profile_pinned_published_on_loopback_and_never_on_all_interfaces(): void
    {
        $service = $this->composeService('triton');

        $this->assertStringContainsString('profiles: ["triton"]', $service);
        $this->assertStringContainsString('nvcr.io/nvidia/tritonserver:${TRITON_TAG:-26.10-vllm-python-py3}', $service);
        $this->assertStringContainsString('"127.0.0.1:${TRITON_PORT:-9000}:9000"', $service);
        $this->assertStringContainsString('"127.0.0.1:${TRITON_KSERVE_PORT:-8010}:8000"', $service);
        $this->assertStringNotContainsString('- "9000:9000"', $service);
        $this->assertStringContainsString('driver: nvidia', $service);
    }

    public function test_the_frontend_is_started_with_the_flags_the_provider_depends_on(): void
    {
        $service = $this->composeService('triton');

        foreach (['openai_frontend/main.py', '--model-repository', '--tool-call-parser', '--default-max-tokens', '--model-control-mode', '--load-model', '--openai-restricted-api', 'inference,model-repository', '--enable-kserve-frontends'] as $needle) {
            $this->assertStringContainsString($needle, $service, $needle);
        }
    }

    public function test_the_health_check_is_a_real_one_token_completion_and_waits_for_the_model_to_load(): void
    {
        $service = $this->composeService('triton');

        $this->assertStringContainsString("'max_tokens': 1", $service);
        $this->assertStringContainsString('/v1/chat/completions', $service);
        $this->assertStringContainsString('start_period: 600s', $service);
    }

    public function test_the_other_servers_health_checks_are_real_completions_too_after_the_cheap_health_probe(): void
    {
        foreach (['vllm', 'llama', 'llama-cpu'] as $name) {
            $service = $this->composeService($name);

            $this->assertStringContainsString('"max_tokens":1', $service, $name);
            $this->assertStringContainsString('/health', $service, $name);
            $this->assertMatchesRegularExpression('/start_period: \d+s/', $service, $name);
        }
    }

    public function test_the_compose_defaults_agree_with_the_provider_config_and_env_example(): void
    {
        $service = $this->composeService('triton');
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match('/\$\{TRITON_API_KEY:-([^}]+)\}/', $service, $key);
        preg_match('/\$\{TRITON_AUTH_HEADER:-([^}]+)\}/', $service, $header);
        preg_match('/\$\{TRITON_MODEL_ALIAS:-([^}]+)\}/', $service, $alias);
        preg_match('/\$\{TRITON_DEFAULT_MAX_TOKENS:-([^}]+)\}/', $service, $tokens);
        preg_match('/\$\{TRITON_TAG:-([^}]+)\}/', $service, $tag);

        $this->assertSame($key[1], config('ai.providers.triton.api_key'));
        $this->assertSame($header[1], config('ai.providers.triton.auth_header'));
        $this->assertSame($alias[1], config('ai.providers.triton.model'));
        $this->assertSame((int) $tokens[1], config('ai.providers.triton.default_max_tokens'));
        $this->assertStringContainsString("TRITON_TAG={$tag[1]}", $example);
        $this->assertStringContainsString("TRITON_API_KEY={$key[1]}", $example);
    }

    public function test_the_default_hosts_do_not_collide_with_another_providers_port(): void
    {
        $this->assertSame('http://127.0.0.1:9000/v1', config('ai.providers.triton.base_url'));
        $this->assertNotSame(config('ai.providers.vllm.base_url'), config('ai.providers.triton.base_url'));
    }

    public function test_the_repository_has_the_four_models_and_each_config_names_what_the_book_says(): void
    {
        $root = base_path('ops/triton/models');

        $this->assertStringContainsString('backend: "vllm"', (string) file_get_contents("{$root}/chat/config.pbtxt"));
        $this->assertStringContainsString('backend: "onnxruntime"', (string) file_get_contents("{$root}/embedder/config.pbtxt"));
        $this->assertStringContainsString('max_queue_delay_microseconds: 2000', (string) file_get_contents("{$root}/reranker/config.pbtxt"));
        $this->assertStringContainsString('count: 2, kind: KIND_GPU', (string) file_get_contents("{$root}/reranker/config.pbtxt"));
        $this->assertStringContainsString('platform: "ensemble"', (string) file_get_contents("{$root}/support_pipeline/config.pbtxt"));
    }

    public function test_the_chat_models_engine_args_are_valid_json_and_agree_with_the_context_window(): void
    {
        $arguments = json_decode((string) file_get_contents(base_path('ops/triton/models/chat/1/model.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(config('ai.budget.window'), $arguments['max_model_len']);
        $this->assertTrue($arguments['enable_prefix_caching']);
    }

    public function test_the_ensemble_maps_every_required_input_of_both_composing_models(): void
    {
        $ensemble = (string) file_get_contents(base_path('ops/triton/models/support_pipeline/config.pbtxt'));

        foreach (['embedder', 'reranker'] as $model) {
            $config = (string) file_get_contents(base_path("ops/triton/models/{$model}/config.pbtxt"));

            preg_match_all('/name: "(input_ids|attention_mask)"/', $config, $inputs);

            preg_match('/model_name: "'.$model.'"(?<step>.*?)(?=model_name:|\z)/s', $ensemble, $step);

            foreach (array_unique($inputs[1]) as $input) {
                $this->assertStringContainsString("key: \"{$input}\"", $step['step'], "{$model} {$input}");
            }
        }
    }

    public function test_the_engine_build_script_is_valid_bash_and_prints_its_commands_without_running_docker(): void
    {
        $syntax = new Process(['bash', '-n', base_path('ops/triton/build-trtllm.sh')]);
        $syntax->run();

        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());

        $dry = new Process(['bash', base_path('ops/triton/build-trtllm.sh')], env: ['DRY_RUN' => '1', 'PATH' => '/usr/bin:/bin']);
        $dry->run();

        $this->assertTrue($dry->isSuccessful(), $dry->getErrorOutput());
        $this->assertStringContainsString('trtllm-build', $dry->getOutput());
        $this->assertStringContainsString('--kv_cache_type paged', $dry->getOutput());
        $this->assertStringContainsString('--max_seq_len 8704', $dry->getOutput());
        $this->assertStringContainsString('tritonserver:25.10-trtllm-python-py3', $dry->getOutput());
        $this->assertStringContainsString('--qformat fp8', $dry->getOutput());
    }

    public function test_the_engine_build_script_sizes_follow_the_environment(): void
    {
        $dry = new Process(['bash', base_path('ops/triton/build-trtllm.sh')], env: ['DRY_RUN' => '1', 'PATH' => '/usr/bin:/bin', 'MAX_SEQ_LEN' => '4096', 'QUANT_FORMAT' => 'int4_awq']);
        $dry->run();

        $this->assertStringContainsString('--max_seq_len 4096', $dry->getOutput());
        $this->assertStringContainsString('/engines/llama-8b-int4_awq', $dry->getOutput());
        $this->assertStringContainsString('--qformat int4_awq', $dry->getOutput());
    }

    public function test_the_build_outputs_and_model_artifacts_are_not_committed(): void
    {
        $ignore = (string) file_get_contents(base_path('.gitignore'));

        foreach (['/ops/triton/models/*/*/*.onnx', '/engines/', '/hf/'] as $line) {
            $this->assertStringContainsString($line, $ignore);
        }
    }

    public function test_the_monitoring_profile_scrapes_every_server_that_has_metrics_and_publishes_only_grafana(): void
    {
        $prometheus = (string) file_get_contents(base_path('ops/monitoring/prometheus.yml'));

        foreach (['vllm:8000', 'llama:8080', 'llama-cpu:8080', 'triton:9000'] as $target) {
            $this->assertStringContainsString($target, $prometheus);
        }

        $this->assertStringNotContainsString('ports:', $this->composeService('prometheus'));
        $this->assertStringContainsString('"127.0.0.1:${GRAFANA_PORT:-3000}:3000"', $this->composeService('grafana'));
        $this->assertStringContainsString('GF_AUTH_ANONYMOUS_ENABLED: "false"', $this->composeService('grafana'));
        $this->assertStringContainsString('profiles: ["monitoring"]', $this->composeService('prometheus'));
    }

    public function test_the_dashboard_is_valid_json_and_every_panel_queries_the_provisioned_datasource(): void
    {
        $dashboard = json_decode((string) file_get_contents(base_path('ops/monitoring/grafana/dashboards/ledgerly-inference.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ledgerly-inference', $dashboard['uid']);
        $this->assertGreaterThanOrEqual(8, count($dashboard['panels']));

        $datasource = (string) file_get_contents(base_path('ops/monitoring/grafana/provisioning/datasources/prometheus.yml'));

        foreach ($dashboard['panels'] as $panel) {
            $this->assertSame('ledgerly-prometheus', $panel['datasource']['uid']);
            $this->assertStringContainsString('uid: ledgerly-prometheus', $datasource);
            $this->assertNotEmpty($panel['targets'][0]['expr']);
        }
    }

    public function test_the_dashboard_uses_metric_names_each_server_documents(): void
    {
        $dashboard = (string) file_get_contents(base_path('ops/monitoring/grafana/dashboards/ledgerly-inference.json'));

        foreach (['vllm:time_to_first_token_seconds_bucket', 'vllm:kv_cache_usage_perc', 'llamacpp:predicted_tokens_seconds', 'nv_inference_queue_duration_us', 'nv_inference_exec_count', 'nv_gpu_utilization'] as $metric) {
            $this->assertStringContainsString($metric, $dashboard);
        }
    }

    public function test_the_kubernetes_values_set_a_gpu_request_and_readiness_probes_on_the_right_paths(): void
    {
        $vllm = (string) file_get_contents(base_path('ops/k8s/vllm-values.yaml'));
        $triton = (string) file_get_contents(base_path('ops/k8s/triton-values.yaml'));

        $this->assertStringContainsString('nvidia.com/gpu: 1', $vllm);
        $this->assertStringContainsString('nvidia.com/gpu: 1', $triton);
        $this->assertMatchesRegularExpression('/readinessProbe:\s+httpGet:\s+path: \/health\s+port: 8000/', $vllm);
        $this->assertMatchesRegularExpression('/readinessProbe:\s+httpGet:\s+path: \/health\/ready\s+port: 9000/', $triton);
        $this->assertStringContainsString('tcpSocket:', $triton);
        $this->assertStringContainsString('secretKeyRef', $vllm);
        $this->assertStringContainsString('secretKeyRef', $triton);
    }

    private function composeService(string $name): string
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        preg_match('/^  '.preg_quote($name, '/').':\n(?<body>(?:    .*\n|\n)+)/m', $compose, $matches);

        return $matches['body'] ?? '';
    }
}
