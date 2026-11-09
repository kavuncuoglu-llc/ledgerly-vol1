<?php

namespace App\Ai\Embeddings;

use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;

final class Embedder
{
    public function __construct(private readonly EmbeddingModel $model) {}

    public function model(): string
    {
        return $this->model->model();
    }

    public function dimensions(): int
    {
        return $this->model->dimensions();
    }

    public function query(string $text): array
    {
        return $this->model->embed([$text], EmbedKind::Query)[0];
    }

    public function document(string $text): array
    {
        return $this->model->embed([$text], EmbedKind::Document)[0];
    }

    public function documents(array $texts): array
    {
        return $this->model->embed(array_values($texts), EmbedKind::Document);
    }
}
