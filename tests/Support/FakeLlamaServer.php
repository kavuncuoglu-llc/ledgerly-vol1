<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class FakeLlamaServer
{
    public const KEY = 'secret-key';

    public static function healthy(array $overrides = []): void
    {
        $settings = [
            'health' => Http::response(['status' => 'ok']),
            'props' => Http::response(self::props()),
            'props_without_key' => Http::response(['error' => ['code' => 401, 'message' => 'Invalid API Key', 'type' => 'authentication_error']], 401),
            'metrics' => Http::response(self::metrics(), 200, ['Content-Type' => 'text/plain; version=0.0.4']),
            'tokenize' => Http::response(['tokens' => [36120, 674, 20213, 16, 374, 83893, 555, 220, 2919, 13]]),
            'chat' => Http::response(self::chatCompletion()),
            'apply_template' => Http::response(['prompt' => '<|begin_of_text|>rendered']),
            'completion' => Http::response(['content' => 'billing', 'stop_type' => 'eos', 'tokens_evaluated' => 300, 'tokens_predicted' => 2, 'tokens_cached' => 280]),
            ...$overrides,
        ];

        Http::fake([
            '*/health' => $settings['health'],
            '*/props' => fn (Request $request) => $request->hasHeader('Authorization')
                ? $settings['props']
                : $settings['props_without_key'],
            '*/metrics' => $settings['metrics'],
            '*/tokenize' => $settings['tokenize'],
            '*/v1/chat/completions' => $settings['chat'],
            '*/apply-template' => $settings['apply_template'],
            '*/completion' => $settings['completion'],
        ]);
    }

    public static function props(array $overrides = []): array
    {
        return [
            'default_generation_settings' => [
                'params' => ['temperature' => 0.8],
                'n_ctx' => 8192,
            ],
            'total_slots' => 2,
            'model_alias' => 'ledgerly-support',
            'model_ftype' => 'Q4_K - Medium',
            'model_path' => '/models/llama-3.1-8b-instruct-q4_k_m.gguf',
            'endpoint_slots' => true,
            'endpoint_props' => false,
            'endpoint_metrics' => true,
            'build_info' => 'b11988-3a1f2c4',
            'is_sleeping' => false,
            ...$overrides,
        ];
    }

    public static function metrics(float $cached = 900, float $uncached = 100, float $drafted = 0, float $accepted = 0, float $longest = 2410): string
    {
        $series = [
            'prompt_tokens_total' => $uncached,
            'prompt_tokens_cached_total' => $cached,
            'prompt_seconds_total' => 1.5,
            'tokens_predicted_total' => 640,
            'tokens_predicted_seconds_total' => 12.1,
            'n_decode_total' => 700,
            'n_tokens_max' => $longest,
            'spec_decode_num_draft_tokens_total' => $drafted,
            'spec_decode_num_accepted_tokens_total' => $accepted,
            'spec_decode_num_drafts_total' => 0,
            'prompt_tokens_seconds' => 546.9,
            'predicted_tokens_seconds' => 52.9,
            'requests_processing' => 0,
            'requests_deferred' => 0,
            'n_busy_slots_per_decode' => 1,
        ];

        $lines = [];

        foreach ($series as $name => $value) {
            $lines[] = "# HELP llamacpp:{$name} fixture";
            $lines[] = "# TYPE llamacpp:{$name} counter";
            $lines[] = "llamacpp:{$name} {$value}";
        }

        return implode("\n", $lines)."\n";
    }

    public static function chatCompletion(array $overrides = []): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'ledgerly-support',
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'ok',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 17,
                'completion_tokens' => 2,
                'total_tokens' => 19,
                'prompt_tokens_details' => ['cached_tokens' => 10],
            ],
            'timings' => [
                'cache_n' => 10,
                'prompt_n' => 7,
                'prompt_ms' => 12.8,
                'prompt_per_second' => 546.9,
                'predicted_n' => 2,
                'predicted_ms' => 37.8,
                'predicted_per_second' => 52.9,
            ],
            ...$overrides,
        ];
    }
}
