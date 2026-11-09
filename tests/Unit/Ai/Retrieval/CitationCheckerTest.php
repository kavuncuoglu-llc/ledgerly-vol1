<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\RetrievedChunk;
use PHPUnit\Framework\TestCase;

class CitationCheckerTest extends TestCase
{
    private const CALCULATED = 'late-fees › How the fee is calculated';

    private const APPLIED = 'late-fees › When a late fee is applied';

    public function test_check_accepts_cited_sentences_that_match_their_passage(): void
    {
        $report = $this->checker()->check(
            'Ledgerly charges 1.5% of the outstanding balance per month, prorated by day ['.self::CALCULATED.']. It is applied automatically 30 days after the due date unless the invoice is disputed ['.self::APPLIED.'].',
            $this->labelMap(),
        );

        $this->assertFalse($report->hasErrors());
        $this->assertSame([self::CALCULATED, self::APPLIED], array_keys($report->cited));
    }

    public function test_check_flags_a_label_that_was_not_provided(): void
    {
        $report = $this->checker()->check('The fee is 1.5% [late-fees › Invented section].', $this->labelMap());

        $this->assertSame(['Citation [late-fees › Invented section] does not match any provided passage.'], $report->errors);
    }

    public function test_check_flags_a_factual_sentence_without_a_citation(): void
    {
        $report = $this->checker()->check('Ledgerly charges 1.5% per month.', $this->labelMap());

        $this->assertSame(['Uncited factual sentence: "Ledgerly charges 1.5% per month."'], $report->errors);
    }

    public function test_check_flags_a_citation_whose_passage_does_not_contain_the_number(): void
    {
        $report = $this->checker()->check('Ledgerly charges 2% per month ['.self::CALCULATED.'].', $this->labelMap());

        $this->assertSame(['Sentence cites ['.self::CALCULATED.'] but shares no key terms or numbers with it.'], $report->errors);
    }

    public function test_check_treats_equal_numbers_written_differently_as_the_same(): void
    {
        $labelMap = [
            self::CALCULATED => $this->chunk('Minimum', 'The default minimum is $1.00 and the limit is 1,000 invoices on the free plan.'),
        ];

        $report = $this->checker()->check('The minimum is $1 and the limit is 1000 invoices ['.self::CALCULATED.'].', $labelMap);

        $this->assertFalse($report->hasErrors());
    }

    public function test_check_flags_a_citation_that_shares_no_key_terms_with_the_passage(): void
    {
        $report = $this->checker()->check('Reminders arrive by carrier pigeon every Tuesday ['.self::CALCULATED.'].', $this->labelMap());

        $this->assertCount(1, $report->errors);
    }

    public function test_check_accepts_a_citation_placed_after_the_full_stop(): void
    {
        $report = $this->checker()->check('The fee is 1.5% of the outstanding balance per month. ['.self::CALCULATED.']', $this->labelMap());

        $this->assertFalse($report->hasErrors());
    }

    public function test_check_does_not_split_a_sentence_at_a_decimal_point(): void
    {
        $sentences = $this->checker()->sentences('The fee is 1.5% per month ['.self::CALCULATED.']. Another sentence follows.');

        $this->assertCount(2, $sentences);
    }

    public function test_check_ignores_list_markers_and_checks_each_step(): void
    {
        $report = $this->checker()->check("1. Open the invoice\n2. Choose Waive late fee", $this->labelMap());

        $this->assertSame(['Uncited factual sentence: "Choose Waive late fee"'], $report->errors);
    }

    public function test_check_accepts_a_decline_with_no_citations(): void
    {
        $report = $this->checker()->check('The help center does not cover that. I can open a support ticket for you.', $this->labelMap());

        $this->assertFalse($report->hasErrors());
        $this->assertSame([], $report->cited);
    }

    public function test_check_only_looks_for_fabricated_labels_when_citations_are_not_required(): void
    {
        $report = $this->checker()->check('Invoice INV-4471 is 18 days overdue.', $this->labelMap(), requireCitations: false);

        $this->assertFalse($report->hasErrors());
    }

    public function test_check_flags_every_label_when_no_passages_were_provided(): void
    {
        $report = $this->checker()->check('The fee is 1.5% [late-fees › How the fee is calculated].', [], requireCitations: false);

        $this->assertSame(1, $report->errorCount());
    }

    private function checker(): CitationChecker
    {
        return new CitationChecker;
    }

    private function labelMap(): array
    {
        return [
            self::CALCULATED => $this->chunk('How the fee is calculated', 'The fee is 1.5% of the outstanding balance per month, prorated by day. It is calculated on the invoice total excluding any previous late fees.'),
            self::APPLIED => $this->chunk('When a late fee is applied', 'A late fee is applied automatically 30 days after the due date unless the invoice is marked as disputed or a payment plan is active.'),
        ];
    }

    private function chunk(string $section, string $body): RetrievedChunk
    {
        return new RetrievedChunk(
            id: 1,
            source: 'help/billing/late-fees.md',
            title: 'Understanding late fees',
            headingPath: "Billing › Understanding late fees › {$section}",
            content: "Understanding late fees › {$section}\n\n{$body}",
            helpVersion: '7b2e9d0',
        );
    }
}
