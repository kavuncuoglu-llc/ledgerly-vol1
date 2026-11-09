<?php

namespace App\Ai\Bench;

use App\Ai\Encoding\DataEncoder;
use App\Ai\Encoding\Shape;
use App\Ai\Exceptions\UnusableBenchmark;
use App\Ai\Http\AiHttpClient;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Support\Collection;

final class EncodingBenchmark
{
    public const FEATURE = 'bench.encoding';

    public const ENCODINGS = [
        'json-pretty' => 'json-pretty',
        'json-compact' => 'json',
        'yaml' => 'yaml',
        'markdown-table' => 'markdown',
        'tsv' => 'tsv',
        'toon' => 'toon',
    ];

    public function __construct(
        private readonly DataEncoder $encoder,
        private readonly TokenCounter $tokens,
        private readonly AiHttpClient $client,
    ) {}

    public function encode(Collection $rows, Shape $shape, array $encodings): array
    {
        $encoded = [];

        foreach ($encodings as $name) {
            $format = self::ENCODINGS[$name] ?? throw UnusableBenchmark::unknownEncoding($name, array_keys(self::ENCODINGS));

            $encoded[$name] = $this->encoder->encode($rows, $shape, format: $format, key: 'invoices');
        }

        return $encoded;
    }

    public function run(array $encoded, array $questions, bool $askModel, ?string $model = null): array
    {
        $results = [];

        foreach ($encoded as $name => $data) {
            $results[] = $this->measure($name, $data, $questions, $askModel, $model);
        }

        return $results;
    }

    private function measure(string $name, string $data, array $questions, bool $askModel, ?string $model): EncodingResult
    {
        $tokens = $this->tokens->count($data);

        if (! $askModel) {
            return new EncodingResult(name: $name, tokens: $tokens, correct: 0, asked: 0);
        }

        return $this->ask($name, $data, $tokens, $questions, $model);
    }

    private function ask(string $name, string $data, int $tokens, array $questions, ?string $model): EncodingResult
    {
        $missed = [];

        $promptTokens = 0;

        foreach ($questions as $index => $question) {
            $response = $this->client->chat(
                messages: BenchPrompt::messages($data, $question['question']),
                options: $this->options($model),
                feature: self::FEATURE,
            );

            $promptTokens += $response->usage->promptTokens;

            if (! BenchPrompt::matches((string) $response->content, $question['expected'])) {
                $missed[] = $index + 1;
            }
        }

        return new EncodingResult(
            name: $name,
            tokens: $tokens,
            correct: count($questions) - count($missed),
            asked: count($questions),
            missedQuestions: $missed,
            modelPromptTokens: $promptTokens,
        );
    }

    private function options(?string $model): array
    {
        $options = [
            'profile' => 'deterministic',
            'max_tokens' => 20,
        ];

        if ($model === null) {
            return $options;
        }

        return [
            ...$options,
            'model' => $model,
        ];
    }
}
