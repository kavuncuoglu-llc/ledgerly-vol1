<?php

namespace App\Ai\Retrieval;

final class ReciprocalRankFusion
{
    public static function merge(array $rankedLists, int $constant, int $limit): array
    {
        $scores = [];

        $first = [];

        $similarity = [];

        $keyword = [];

        foreach ($rankedLists as $list) {
            foreach (array_values($list) as $rank => $chunk) {
                $scores[$chunk->id] = ($scores[$chunk->id] ?? 0.0) + 1 / ($constant + $rank + 1);

                $first[$chunk->id] ??= $chunk;

                $similarity[$chunk->id] = max($similarity[$chunk->id] ?? 0.0, $chunk->similarity);

                $keyword[$chunk->id] = ($keyword[$chunk->id] ?? false) || $chunk->keywordMatched;
            }
        }

        arsort($scores);

        return collect(array_keys($scores))
            ->take($limit)
            ->map(fn (int $id): RetrievedChunk => $first[$id]->fusedWith($scores[$id], $similarity[$id], $keyword[$id]))
            ->values()
            ->all();
    }
}
