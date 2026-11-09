<?php

namespace App\Ai\Retrieval;

interface Retriever
{
    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array;
}
