<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class FakeTritonServer
{
    public const KEY = 'triton-secret';

    public const HEADER = 'x-api-key';

    public static function healthy(array $overrides = []): void
    {
        $settings = [
            'ready' => Http::response('', 200),
            'models' => Http::response(self::models()),
            'models_without_key' => Http::response(self::unauthorized(), 401),
            'metrics' => Http::response(self::metrics(), 200, ['Content-Type' => 'text/plain; version=0.0.4']),
            'chat' => Http::response(self::chatCompletion()),
            'live' => Http::response('', 200),
            'kserve_ready' => Http::response('', 200),
            'metadata' => Http::response(self::metadata()),
            'index' => Http::response(self::index()),
            'configs' => self::configs(),
            ...$overrides,
        ];

        Http::fake([
            'http://triton.test:9000/health/ready' => $settings['ready'],
            'http://triton.test:9000/v1/models' => fn (Request $request) => $request->hasHeader(self::HEADER)
                ? $settings['models']
                : $settings['models_without_key'],
            'http://triton.test:9000/metrics' => $settings['metrics'],
            'http://triton.test:9000/v1/chat/completions' => $settings['chat'],
            'http://kserve.test:8000/v2/health/live' => $settings['live'],
            'http://kserve.test:8000/v2/health/ready' => $settings['kserve_ready'],
            'http://kserve.test:8000/v2/repository/index' => $settings['index'],
            'http://kserve.test:8000/v2/models/*/config' => fn (Request $request) => self::configFor($request->url(), $settings['configs']),
            'http://kserve.test:8000/v2' => $settings['metadata'],
        ]);
    }

    public static function models(array $ids = ['chat']): array
    {
        return [
            'object' => 'list',
            'data' => array_map(fn (string $id): array => [
                'id' => $id,
                'object' => 'model',
                'created' => 1794250511,
                'owned_by' => 'Triton Inference Server',
            ], $ids),
        ];
    }

    public static function unauthorized(): array
    {
        return [
            'error' => [
                'message' => 'Invalid or missing authentication header',
                'type' => 'authentication_error',
                'code' => 'invalid_auth',
            ],
        ];
    }

    public static function metadata(): array
    {
        return [
            'name' => 'triton',
            'version' => '2.74.0',
            'extensions' => ['model_repository', 'model_configuration', 'statistics'],
        ];
    }

    public static function index(array $rows = []): array
    {
        return $rows ?: [
            ['name' => 'chat', 'version' => '1', 'state' => 'READY'],
            ['name' => 'reranker', 'version' => '1', 'state' => 'READY'],
        ];
    }

    public static function configs(): array
    {
        return [
            'chat' => [
                'name' => 'chat',
                'backend' => 'vllm',
                'max_batch_size' => 0,
                'instance_group' => [
                    ['name' => 'chat_0', 'kind' => 'KIND_MODEL', 'count' => 1],
                ],
            ],
            'reranker' => [
                'name' => 'reranker',
                'backend' => 'onnxruntime',
                'max_batch_size' => 64,
                'dynamic_batching' => ['max_queue_delay_microseconds' => '2000'],
                'instance_group' => [
                    ['name' => 'reranker_0', 'kind' => 'KIND_GPU', 'count' => 2, 'gpus' => [0]],
                ],
                'model_warmup' => [['name' => 'pair', 'batch_size' => 1]],
            ],
        ];
    }

    public static function metrics(array $overrides = []): string
    {
        $series = [
            'nv_inference_request_success{model="reranker",version="1"}' => 48213,
            'nv_inference_request_failure{model="reranker",version="1"}' => 0,
            'nv_inference_count{model="reranker",version="1"}' => 48213,
            'nv_inference_exec_count{model="reranker",version="1"}' => 3102,
            'nv_inference_queue_duration_us{model="reranker",version="1"}' => 91402855,
            'nv_gpu_utilization{gpu_uuid="GPU-0"}' => 0.71,
            'nv_gpu_memory_used_bytes{gpu_uuid="GPU-0"}' => 19327352832,
            'nv_gpu_memory_total_bytes{gpu_uuid="GPU-0"}' => 25769803776,
            ...$overrides,
        ];

        $lines = [];

        foreach ($series as $name => $value) {
            $lines[] = "{$name} {$value}";
        }

        return implode("\n", $lines)."\n";
    }

    public static function chatCompletion(array $overrides = []): array
    {
        return [
            'id' => 'cmpl-5c1e0f6e-8f2d-4a38-9a77-0b6f1d9a5d11',
            'object' => 'chat.completion',
            'created' => 1794250511,
            'model' => 'chat',
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => $overrides['content'] ?? 'ok',
                    ],
                ],
            ],
            'usage' => $overrides['usage'] ?? [
                'prompt_tokens' => 17,
                'completion_tokens' => 2,
                'total_tokens' => 19,
            ],
        ];
    }

    private static function configFor(string $url, array $configs): PromiseInterface
    {
        preg_match('#/v2/models/([^/]+)/config$#', $url, $matches);

        $config = $configs[$matches[1] ?? ''] ?? null;

        if ($config === null) {
            return Http::response(['error' => 'Request for unknown model'], 400);
        }

        return Http::response($config);
    }
}
