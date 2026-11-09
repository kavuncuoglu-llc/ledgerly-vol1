<?php

namespace Tests\Unit\Ai\Compare;

use App\Ai\Compare\Scorers\ExactMatchScorer;
use App\Ai\Compare\Scorers\JudgeScorer;
use App\Ai\Compare\Scorers\RetrievalRecallScorer;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use App\Ai\Facades\Ai;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\RetrievedChunk;
use Tests\Support\PlainTextModel;
use Tests\TestCase;

class ScorersTest extends TestCase
{
    public function test_exact_match_reads_the_category_from_a_json_object(): void
    {
        $scorer = new ExactMatchScorer('billing');

        $this->assertSame(1.0, $scorer->score($this->response('{"category":"billing","invoice_number":null}'))->value);
        $this->assertSame(0.0, $scorer->score($this->response('{"category":"howto"}'))->value);
    }

    public function test_exact_match_accepts_a_bare_word_in_any_case_with_punctuation(): void
    {
        $scorer = new ExactMatchScorer('billing');

        $this->assertSame(1.0, $scorer->score($this->response(" Billing.\n"))->value);
        $this->assertSame(1.0, $scorer->score($this->response('"billing"'))->value);
    }

    public function test_exact_match_scores_an_empty_answer_as_a_miss_and_says_so(): void
    {
        $score = (new ExactMatchScorer('bug'))->score($this->response(''));

        $this->assertSame(0.0, $score->value);
        $this->assertStringContainsString('(nothing)', $score->detail);
    }

    public function test_recall_is_one_when_the_answer_cites_the_expected_section(): void
    {
        $scorer = $this->recall(['late-fees›How the fee is calculated']);

        $score = $scorer->score($this->response('The fee is 1.5% [late-fees › How the fee is calculated].'));

        $this->assertSame(1.0, $score->value);
    }

    public function test_recall_is_zero_when_it_cites_another_passage_or_none(): void
    {
        $scorer = $this->recall(['late-fees›How the fee is calculated']);

        $this->assertSame(0.0, $scorer->score($this->response('See [refunds › Introduction].'))->value);
        $this->assertSame(0.0, $scorer->score($this->response('The fee is 1.5%.'))->value);
    }

    public function test_recall_ignores_a_citation_that_matches_no_passage(): void
    {
        $scorer = $this->recall(['late-fees›How the fee is calculated']);

        $score = $scorer->score($this->response('Something [made up].'));

        $this->assertSame(0.0, $score->value);
    }

    public function test_an_off_topic_question_is_answered_correctly_by_declining_without_a_citation(): void
    {
        $scorer = $this->recall([]);

        $this->assertSame(1.0, $scorer->score($this->response('I could not find that in the help center. Would you like me to open a support ticket?'))->value);
        $this->assertSame(0.0, $scorer->score($this->response('Try Luigi on Main Street.'))->value);
        $this->assertSame(0.0, $scorer->score($this->response('I could not find that [late-fees › How the fee is calculated].'))->value);
    }

    public function test_the_judge_grade_maps_one_to_five_onto_zero_to_one(): void
    {
        Ai::fake()->for('compare.judge')->respondWithJson(['score' => 5, 'reason' => 'grounded'])
            ->for('compare.judge')->respondWithJson(['score' => 3, 'reason' => 'partial'])
            ->for('compare.judge')->respondWithJson(['score' => 1, 'reason' => 'wrong']);
        $scorer = new JudgeScorer(Ai::chat('fake'), 'What is the fee?', '[late-fees]\n1.5%');

        $this->assertSame(1.0, $scorer->score($this->response('1.5%'))->value);
        $this->assertSame(0.5, $scorer->score($this->response('about 1%'))->value);
        $this->assertSame(0.0, $scorer->score($this->response('no idea'))->value);
    }

    public function test_the_judge_sees_the_question_the_passages_and_the_answer(): void
    {
        Ai::fake()->respondWithJson(['score' => 4, 'reason' => 'ok']);

        (new JudgeScorer(Ai::chat('fake'), 'What is the fee?', '[late-fees]\n1.5%'))->score($this->response('It is 1.5%.'));

        Ai::assertSent(function ($request): bool {
            $user = (string) $request->messages[array_key_last($request->messages)]['content'];

            return $request->feature === 'compare.judge'
                && str_contains($user, 'What is the fee?')
                && str_contains($user, '[late-fees]')
                && str_contains($user, 'It is 1.5%.')
                && $request->options['temperature'] === 0;
        });
    }

    public function test_a_judge_that_returns_prose_scores_zero_instead_of_crashing(): void
    {
        $scorer = new JudgeScorer(new PlainTextModel('Looks fine to me.'), 'q', '');

        $score = $scorer->score($this->response('a'));

        $this->assertSame(0.0, $score->value);
        $this->assertStringContainsString('did not return a score', $score->detail);
    }

    public function test_a_judge_that_wraps_its_json_in_prose_is_still_read(): void
    {
        $scorer = new JudgeScorer(new PlainTextModel('Here is my verdict: {"score": 5, "reason": "good"} thanks'), 'q', '');

        $this->assertSame(1.0, $scorer->score($this->response('a'))->value);
    }

    public function test_a_judge_without_structured_output_gets_no_response_format_but_the_same_rubric(): void
    {
        $judge = new PlainTextModel('{"score": 4, "reason": "ok"}');

        (new JudgeScorer($judge, 'q', ''))->score($this->response('a'));

        $this->assertNull($judge->received[0]->responseFormat);
        $this->assertStringContainsString('Grade the answer from 1 to 5', $judge->received[0]->messages[0]['content']);
    }

    private function recall(array $expected): RetrievalRecallScorer
    {
        return new RetrievalRecallScorer($expected, [
            'late-fees › How the fee is calculated' => $this->chunk('late-fees', 'How the fee is calculated', 'The fee is 1.5% of the balance.'),
            'refunds › Introduction' => $this->chunk('refunds', 'Introduction', 'Refunds take five days.'),
        ], new CitationChecker);
    }

    private function chunk(string $slug, string $section, string $content): RetrievedChunk
    {
        return new RetrievedChunk(
            id: crc32($slug.$section),
            source: "resources/help/billing/{$slug}.md",
            title: $slug,
            headingPath: "{$slug} › {$section}",
            content: "## {$section}\n\n{$content}",
        );
    }

    private function response(string $content): ChatResponse
    {
        return new ChatResponse('id', 'm', $content, [], FinishReason::Stop, new Usage(10, 5), []);
    }
}
