<?php

namespace App\Ai\Embeddings;

use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Http\AiHttpClient;

final class Embedder
{
    public function __construct(
        private readonly AiHttpClient $client,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function query(string $text): array
    {
        return $this->embed('search_query: '.$text);
    }

    public function document(string $text): array
    {
        return $this->embed('search_document: '.$text);
    }

    public function documents(array $texts): array
    {
        if (count($texts) <= 1) {
            return array_map(fn (string $text): array => $this->document($text), array_values($texts));
        }

        $response = $this->client->post('/embeddings', [
            'model' => $this->model,
            'input' => array_map(fn (string $text): string => 'search_document: '.$text, array_values($texts)),
        ]);

        return $this->vectorsOf($response->json('data'), count($texts));
    }

    private function vectorsOf(mixed $rows, int $expected): array
    {
        if (! is_array($rows)) {
            throw EmbeddingFailed::malformed();
        }

        if (count($rows) !== $expected) {
            throw EmbeddingFailed::malformed();
        }

        usort($rows, fn (array $left, array $right): int => ($left['index'] ?? 0) <=> ($right['index'] ?? 0));

        return array_map(fn (array $row): array => $this->checked($row['embedding'] ?? null), $rows);
    }

    private function checked(mixed $vector): array
    {
        if (! is_array($vector)) {
            throw EmbeddingFailed::malformed();
        }

        if (count($vector) !== $this->dimensions) {
            throw EmbeddingFailed::wrongDimensions($this->model, $this->dimensions, count($vector));
        }

        return array_map('floatval', $vector);
    }

    private function embed(string $input): array
    {
        $response = $this->client->post('/embeddings', [
            'model' => $this->model,
            'input' => $input,
        ]);

        return $this->checked($response->json('data.0.embedding'));
    }
}
