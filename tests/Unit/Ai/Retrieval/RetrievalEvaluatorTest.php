<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\GoldenQuestion;
use App\Ai\Retrieval\RetrievalEvaluator;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Retrieval\Retriever;
use PHPUnit\Framework\TestCase;

class RetrievalEvaluatorTest extends TestCase
{
    public function test_evaluate_scores_recall_precision_and_mrr_at_each_cutoff(): void
    {
        $retriever = $this->retrieverReturning([
            'first hit' => ['a › One', 'b › Two'],
            'third hit' => ['x › X', 'y › Y', 'a › One'],
            'no hit' => ['x › X'],
        ]);

        $report = (new RetrievalEvaluator)->evaluate($retriever, [
            new GoldenQuestion(1, 'first hit', ['a›One']),
            new GoldenQuestion(2, 'third hit', ['a›One']),
            new GoldenQuestion(3, 'no hit', ['a›One']),
        ]);

        $this->assertEqualsWithDelta(1 / 3, $report->recall[1], 0.001);
        $this->assertEqualsWithDelta(2 / 3, $report->recall[3], 0.001);
        $this->assertEqualsWithDelta(2 / 3, $report->recall[5], 0.001);
        $this->assertEqualsWithDelta((1 + 0 + 0) / 3, $report->precision[1], 0.001);
        $this->assertEqualsWithDelta((1 / 3 + 1 / 3 + 0) / 3, $report->precision[3], 0.001);
        $this->assertEqualsWithDelta((1 + 1 / 3 + 0) / 3, $report->mrr, 0.001);
        $this->assertSame([3], array_column($report->misses, 'id'));
    }

    public function test_evaluate_counts_an_empty_result_as_correct_for_an_off_topic_question(): void
    {
        $retriever = $this->retrieverReturning([
            'best pizza near me' => [],
            'weather' => ['x › X'],
        ]);

        $report = (new RetrievalEvaluator)->evaluate($retriever, [
            new GoldenQuestion(1, 'best pizza near me', []),
            new GoldenQuestion(2, 'weather', []),
        ]);

        $this->assertSame(1, $report->emptyCorrect);
        $this->assertSame(2, $report->emptyTotal);
    }

    public function test_evaluate_reports_zero_when_there_are_no_answerable_questions(): void
    {
        $report = (new RetrievalEvaluator)->evaluate($this->retrieverReturning([]), []);

        $this->assertSame(0.0, $report->recall[5]);
        $this->assertSame(0.0, $report->mrr);
    }

    private function retrieverReturning(array $labelsByQuestion): Retriever
    {
        return new class($labelsByQuestion) implements Retriever
        {
            public function __construct(private readonly array $labelsByQuestion) {}

            public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
            {
                return array_map(function (string $label): RetrievedChunk {
                    [$slug, $section] = explode(' › ', $label);

                    return new RetrievedChunk(
                        id: 1,
                        source: "help/billing/{$slug}.md",
                        title: $slug,
                        headingPath: "Billing › {$slug} › {$section}",
                        content: "{$slug} › {$section}\n\nText.",
                    );
                }, $this->labelsByQuestion[$question] ?? []);
            }
        };
    }
}
