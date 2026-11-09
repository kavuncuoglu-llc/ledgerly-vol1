<?php

namespace App\Ai\Contracts;

interface Reranker
{
    public function score(string $question, array $passages): array;
}
