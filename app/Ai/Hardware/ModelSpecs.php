<?php

namespace App\Ai\Hardware;

use App\Ai\Exceptions\UnknownModelArchitecture;

final class ModelSpecs
{
    private const FIELDS = [
        'parameters_billions',
        'layers',
        'kv_heads',
        'head_dim',
    ];

    public function resolve(string $model, array $overrides = [], ?OllamaClient $server = null): ModelSpec
    {
        $facts = $this->catalogueFacts($model);

        $source = $facts === []
            ? ''
            : 'catalogue';

        $live = $server === null
            ? []
            : $this->liveFacts($server, $model);

        if ($live !== []) {
            $facts = [...$facts, ...$live];

            $source = 'server';
        }

        $overridden = array_filter($overrides, fn (mixed $value): bool => $value !== null && $value !== '');

        if (array_intersect_key($overridden, array_flip([...self::FIELDS, 'quantization'])) !== []) {
            $facts = [...$facts, ...$overridden];

            if (isset($overridden['parameters_billions'])) {
                unset($facts['weights_bytes']);
            }

            $source = $source === ''
                ? 'options'
                : "{$source} + options";
        }

        return $this->specFrom($model, $facts, $source);
    }

    public function quantizationLadder(): array
    {
        return array_keys(config('ai.hardware.quantizations'));
    }

    public function bytesPerParameter(string $quantization): float
    {
        return (float) (config('ai.hardware.quantizations')[strtolower($quantization)] ?? 0);
    }

    private function specFrom(string $model, array $facts, string $source): ModelSpec
    {
        $quantization = strtolower((string) ($facts['quantization'] ?? config('ai.hardware.default_quantization')));

        $missing = array_values(array_filter(
            ['layers', 'kv_heads', 'head_dim'],
            fn (string $field): bool => empty($facts[$field]),
        ));

        $weights = (int) ($facts['weights_bytes'] ?? 0);

        if ($weights === 0 && empty($facts['parameters_billions'])) {
            $missing[] = 'parameters_billions';
        }

        if ($missing !== []) {
            throw UnknownModelArchitecture::for($model, $missing);
        }

        if ($weights === 0) {
            $weights = $this->weightsFromParameters((float) $facts['parameters_billions'], $quantization, $model);
        }

        return new ModelSpec(
            name: $model,
            weightsBytes: $weights,
            layers: (int) $facts['layers'],
            kvHeads: (int) $facts['kv_heads'],
            headDim: (int) $facts['head_dim'],
            quantization: $quantization,
            source: $source,
        );
    }

    private function weightsFromParameters(float $billions, string $quantization, string $model): int
    {
        $bytesPerParameter = $this->bytesPerParameter($quantization);

        if ($bytesPerParameter === 0.0) {
            throw UnknownModelArchitecture::for($model, ['quantization (one of '.implode(', ', $this->quantizationLadder()).')']);
        }

        return (int) round($billions * 1_000_000_000 * $bytesPerParameter);
    }

    private function catalogueFacts(string $model): array
    {
        if (! preg_match('/^(?<family>[a-z0-9.\-]+):(?<size>\d+(?:\.\d+)?b)(?:-(?<tags>.+))?$/i', $model, $parts)) {
            return [];
        }

        $architecture = config('ai.hardware.architectures')[strtolower($parts['family'])][strtolower($parts['size'])] ?? null;

        if ($architecture === null) {
            return [];
        }

        $quantization = $this->quantizationIn($parts['tags'] ?? '');

        return [
            ...$architecture,
            ...($quantization === '' ? [] : ['quantization' => $quantization]),
        ];
    }

    private function quantizationIn(string $tags): string
    {
        foreach ($this->quantizationLadder() as $quantization) {
            if (preg_match('/(^|[-_])'.preg_quote($quantization, '/').'($|[-_])/i', $tags)) {
                return $quantization;
            }
        }

        if (preg_match('/(^|[-_])fp16($|[-_])/i', $tags)) {
            return 'f16';
        }

        return '';
    }

    private function liveFacts(OllamaClient $server, string $model): array
    {
        $show = $server->show($model);

        if ($show === []) {
            return [];
        }

        $info = $show['model_info'];

        $architecture = (string) ($info['general.architecture'] ?? '');

        $heads = (int) ($info["{$architecture}.attention.head_count"] ?? 0);

        $embedding = (int) ($info["{$architecture}.embedding_length"] ?? 0);

        $headDim = (int) ($info["{$architecture}.attention.key_length"] ?? 0);

        if ($headDim === 0 && $heads > 0) {
            $headDim = intdiv($embedding, $heads);
        }

        return array_filter([
            'layers' => (int) ($info["{$architecture}.block_count"] ?? 0),
            'kv_heads' => (int) ($info["{$architecture}.attention.head_count_kv"] ?? $heads),
            'head_dim' => $headDim,
            'quantization' => strtolower((string) ($show['details']['quantization_level'] ?? '')),
            'weights_bytes' => $server->installedBytes($model),
        ]);
    }
}
