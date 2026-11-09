<?php

namespace App\Ai\Embeddings;

use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Http\ModelEndpoint;

final class Embedder
{
    public function __construct(
        private readonly ModelEndpoint $endpoint,
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

    private function embed(string $input): array
    {
        $response = $this->endpoint->post('/embeddings', [
            'model' => $this->model,
            'input' => $input,
        ]);

        $vector = $response->json('data.0.embedding');

        if (! is_array($vector)) {
            throw EmbeddingFailed::malformed();
        }

        if (count($vector) !== $this->dimensions) {
            throw EmbeddingFailed::wrongDimensions($this->model, $this->dimensions, count($vector));
        }

        return array_map('floatval', $vector);
    }
}
