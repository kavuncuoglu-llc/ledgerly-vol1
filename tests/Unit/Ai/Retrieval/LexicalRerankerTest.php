<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\LexicalReranker;
use PHPUnit\Framework\TestCase;

class LexicalRerankerTest extends TestCase
{
    public function test_score_ranks_the_passage_sharing_more_question_terms_higher(): void
    {
        $scores = (new LexicalReranker)->score('minimum late fee amount', [
            'Reminders are sent by email.',
            'A fee under $1.00 is not charged; the minimum late fee is one dollar.',
        ]);

        $this->assertGreaterThan($scores[0], $scores[1]);
    }

    public function test_score_returns_one_score_per_passage_in_order(): void
    {
        $scores = (new LexicalReranker)->score('late fee', ['a', 'late fee', 'fee']);

        $this->assertCount(3, $scores);
        $this->assertSame(1.0, $scores[1]);
    }

    public function test_score_is_zero_for_a_question_without_usable_terms(): void
    {
        $scores = (new LexicalReranker)->score('a?', ['late fee']);

        $this->assertSame([0.0], $scores);
    }
}
