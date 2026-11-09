<?php

namespace App\Ai\Retrieval;

interface Reranker
{
    public function score(string $question, array $passages): array;
}
