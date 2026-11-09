<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class FakeVllmServer
{
    public const KEY = 'secret-key';

    public static function healthy(array $overrides = []): void
    {
        $settings = [
            'health' => Http::response('', 200),
            'version' => Http::response(['version' => '0.32.0']),
            'models' => Http::response(self::models()),
            'models_without_key' => Http::response(['error' => 'Unauthorized'], 401),
            'metrics' => Http::response(self::metrics(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']),
            'chat' => Http::response(self::chatCompletion()),
            'structured' => Http::response(self::chatCompletion(['content' => '{"category": "billing"}'])),
            ...$overrides,
        ];

        Http::fake([
            '*/health' => $settings['health'],
            '*/version' => $settings['version'],
            '*/v1/models' => fn (Request $request) => $request->hasHeader('Authorization')
                ? $settings['models']
                : $settings['models_without_key'],
            '*/metrics' => $settings['metrics'],
            '*/v1/chat/completions' => fn (Request $request) => isset($request['response_format'])
                ? $settings['structured']
                : $settings['chat'],
        ]);
    }

    public static function models(array $extra = [], int $maxModelLen = 8192): array
    {
        return [
            'object' => 'list',
            'data' => [
                [
                    'id' => 'ledgerly-support',
                    'object' => 'model',
                    'created' => 1794250511,
                    'owned_by' => 'vllm',
                    'root' => 'neuralmagic/Meta-Llama-3.1-8B-Instruct-FP8',
                    'parent' => null,
                    'max_model_len' => $maxModelLen,
                    'permission' => [],
                ],
                ...$extra,
            ],
        ];
    }

    public static function adapter(string $name, string $parent = 'ledgerly-support'): array
    {
        return [
            'id' => $name,
            'object' => 'model',
            'created' => 1794250511,
            'owned_by' => 'vllm',
            'root' => "/adapters/{$name}",
            'parent' => $parent,
            'max_model_len' => null,
            'permission' => [],
        ];
    }

    public static function metrics(array $overrides = []): string
    {
        $cacheConfig = [
            'block_size' => '16',
            'cache_dtype' => 'auto',
            'enable_prefix_caching' => 'True',
            'engine' => '0',
            'gpu_memory_utilization' => '0.92',
            'kv_cache_max_concurrency' => '25.94',
            'kv_cache_size_tokens' => '212480',
            'num_gpu_blocks' => '13280',
            ...($overrides['cache_config'] ?? []),
        ];

        $series = [
            'num_requests_running' => 0,
            'num_requests_waiting' => 0,
            'kv_cache_usage_perc' => 0.12,
            'prefix_cache_queries_total' => 1000,
            'prefix_cache_hits_total' => 900,
            'num_preemptions_total' => 0,
            'prompt_tokens_total' => 5200,
            'generation_tokens_total' => 640,
            ...($overrides['series'] ?? []),
        ];

        $lines = [
            '# HELP vllm:cache_config_info Information of the LLMEngine CacheConfig',
            '# TYPE vllm:cache_config_info gauge',
            'vllm:cache_config_info{'.self::labels($cacheConfig).'} 1.0',
        ];

        foreach ($series as $name => $value) {
            $lines[] = "# HELP vllm:{$name} fixture";
            $lines[] = "# TYPE vllm:{$name} gauge";
            $lines[] = "vllm:{$name}{engine=\"0\",model_name=\"ledgerly-support\"} {$value}";
        }

        return implode("\n", $lines)."\n";
    }

    public static function chatCompletion(array $overrides = []): array
    {
        return [
            'id' => 'chatcmpl-9f2c4a1be7d34c0a8e5b6d7f10293847',
            'object' => 'chat.completion',
            'created' => 1794250511,
            'model' => 'ledgerly-support',
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
                'prompt_tokens_details' => ['cached_tokens' => 10],
            ],
        ];
    }

    private static function labels(array $labels): string
    {
        $pairs = [];

        foreach ($labels as $name => $value) {
            $pairs[] = "{$name}=\"{$value}\"";
        }

        return implode(',', $pairs);
    }
}
