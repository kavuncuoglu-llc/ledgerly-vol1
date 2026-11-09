<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class FakeOllama
{
    public static function healthy(array $overrides = []): void
    {
        $settings = [
            'version' => '0.40.15',
            'models' => [
                'ledgerly-support:latest' => self::model('ledgerly-support:latest', parameters: 'num_ctx 8192'),
                'nomic-embed-text:latest' => self::model('nomic-embed-text:latest', parameters: '', quantization: 'F16', size: '137M'),
            ],
            'running_after_compatible' => [self::running('ledgerly-support:latest', contextLength: 8192, minutesLeft: 30)],
            'running_after_native' => [self::running('ledgerly-support:latest', contextLength: 8192, minutesLeft: 30)],
            'compatible' => Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 2]]),
            'native' => Http::response(['message' => ['role' => 'assistant', 'content' => 'ok'], 'done' => true, 'prompt_eval_count' => 17, 'eval_count' => 2]),
            ...$overrides,
        ];

        $psCalls = 0;

        Http::fake([
            '*/api/version' => $settings['version'] === ''
                ? Http::response('', 500)
                : Http::response(['version' => $settings['version']]),
            '*/api/tags' => Http::response(['models' => array_values(array_map(
                fn (array $model): array => $model['tag'],
                $settings['models'],
            ))]),
            '*/api/show' => function (Request $request) use ($settings) {
                $model = $settings['models'][self::tagged((string) $request['model'])] ?? null;

                return $model === null
                    ? Http::response(['error' => 'model not found'], 404)
                    : Http::response($model['show']);
            },
            '*/api/ps' => function () use (&$psCalls, $settings) {
                $psCalls++;

                return Http::response(['models' => $psCalls === 1
                    ? $settings['running_after_compatible']
                    : $settings['running_after_native']]);
            },
            '*/v1/chat/completions' => $settings['compatible'],
            '*/api/chat' => $settings['native'],
        ]);
    }

    public static function model(string $name, string $parameters = '', string $quantization = 'Q4_K_M', string $size = '8.0B', string $digest = '46e0c10c039e1111'): array
    {
        return [
            'tag' => [
                'name' => $name,
                'model' => $name,
                'size' => 4_920_753_328,
                'digest' => $digest,
                'details' => ['quantization_level' => $quantization, 'parameter_size' => $size],
            ],
            'show' => [
                'parameters' => $parameters,
                'details' => ['quantization_level' => $quantization, 'parameter_size' => $size],
                'model_info' => ['general.architecture' => 'llama'],
            ],
        ];
    }

    public static function running(string $name, int $contextLength, int $minutesLeft, float $gpuShare = 1.0, int $size = 5_800_000_000): array
    {
        return [
            'name' => $name,
            'size' => $size,
            'size_vram' => (int) round($size * $gpuShare),
            'context_length' => $contextLength,
            'expires_at' => now()->addMinutes($minutesLeft)->toIso8601String(),
        ];
    }

    private static function tagged(string $model): string
    {
        return str_contains($model, ':')
            ? $model
            : "{$model}:latest";
    }
}
