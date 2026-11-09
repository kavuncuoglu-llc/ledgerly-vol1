<?php

namespace App\Ai\Retrieval;

use App\Ai\Contracts\Reranker;
use App\Ai\Exceptions\SidecarFailed;
use App\Ai\Sidecar\SidecarClient;

final class SidecarReranker implements Reranker
{
    public function __construct(private readonly SidecarClient $client) {}

    public function score(string $question, array $passages): array
    {
        if ($passages === []) {
            return [];
        }

        $scores = $this->client->rerank($question, $passages);

        if (count($scores) !== count($passages)) {
            throw SidecarFailed::mismatchedScores(count($passages), count($scores));
        }

        return $scores;
    }
}
