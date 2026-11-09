<?php

namespace App\Ai\Contracts;

interface EmbeddingModel
{
    public function embed(array $texts, EmbedKind $kind): array;

    public function dimensions(): int;

    public function model(): string;

    public function name(): string;
}
