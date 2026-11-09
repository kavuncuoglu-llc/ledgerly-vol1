<?php

namespace App\Ai\Retrieval;

final class RetrievalEvaluator
{
    public const CUTOFFS = [1, 3, 5];

    public function evaluate(Retriever $retriever, array $questions): EvalReport
    {
        $answerable = [];

        $emptyCorrect = 0;

        $emptyTotal = 0;

        $elapsedMs = 0.0;

        foreach ($questions as $question) {
            $started = hrtime(true);

            $retrieved = $retriever->retrieve($question->question, new RetrievalScope, k: max(self::CUTOFFS));

            $elapsedMs += (hrtime(true) - $started) / 1_000_000;

            if ($question->expectsNothing()) {
                $emptyTotal++;

                if ($retrieved === []) {
                    $emptyCorrect++;
                }

                continue;
            }

            $answerable[] = [
                'question' => $question,
                'hits' => array_map(fn (RetrievedChunk $chunk): bool => $this->isCorrect($chunk, $question), $retrieved),
                'retrieved' => $retrieved,
            ];
        }

        return new EvalReport(
            recall: $this->averagedAt($this->recallAt(...), $answerable),
            precision: $this->averagedAt($this->precisionAt(...), $answerable),
            mrr: $this->meanReciprocalRank($answerable),
            emptyCorrect: $emptyCorrect,
            emptyTotal: $emptyTotal,
            misses: $this->misses($answerable),
            questions: count($questions),
            queryMs: $questions === []
                ? 0.0
                : $elapsedMs / count($questions),
        );
    }

    private function recallAt(array $hits, int $k): float
    {
        if (in_array(true, array_slice($hits, 0, $k), true)) {
            return 1.0;
        }

        return 0.0;
    }

    private function precisionAt(array $hits, int $k): float
    {
        return count(array_filter(array_slice($hits, 0, $k))) / $k;
    }

    private function isCorrect(RetrievedChunk $chunk, GoldenQuestion $question): bool
    {
        foreach ($question->chunks as $label) {
            if ($chunk->covers($label)) {
                return true;
            }
        }

        return false;
    }

    private function averagedAt(callable $measure, array $answerable): array
    {
        $averages = [];

        foreach (self::CUTOFFS as $k) {
            $values = array_map(fn (array $row): float => $measure($row['hits'], $k), $answerable);

            $averages[$k] = $values === []
                ? 0.0
                : array_sum($values) / count($values);
        }

        return $averages;
    }

    private function meanReciprocalRank(array $answerable): float
    {
        if ($answerable === []) {
            return 0.0;
        }

        $reciprocals = array_map(function (array $row): float {
            $position = array_search(true, $row['hits'], true);

            return $position === false
                ? 0.0
                : 1 / ($position + 1);
        }, $answerable);

        return array_sum($reciprocals) / count($reciprocals);
    }

    private function misses(array $answerable): array
    {
        $misses = [];

        foreach ($answerable as $row) {
            if (in_array(true, $row['hits'], true)) {
                continue;
            }

            $misses[] = [
                'id' => $row['question']->id,
                'question' => $row['question']->question,
                'expected' => $row['question']->chunks,
                'retrieved' => array_map(fn (RetrievedChunk $chunk): string => $chunk->label(), $row['retrieved']),
            ];
        }

        return $misses;
    }
}
