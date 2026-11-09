<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\LabelMap;
use App\Ai\Retrieval\RetrievedChunk;
use PHPUnit\Framework\TestCase;

class RetrievedChunkTest extends TestCase
{
    public function test_label_is_the_article_slug_and_the_section_heading(): void
    {
        $this->assertSame('late-fees › How the fee is calculated', $this->chunk()->label());
    }

    public function test_url_points_at_the_help_article_and_the_heading_anchor(): void
    {
        $this->assertSame('/help/billing/late-fees#how-the-fee-is-calculated', $this->chunk()->url());
    }

    public function test_url_has_no_anchor_for_the_introduction_because_the_article_has_no_such_heading(): void
    {
        $chunk = new RetrievedChunk(
            id: 1,
            source: 'help/billing/late-fees.md',
            title: 'Understanding late fees',
            headingPath: 'Billing › Understanding late fees › Introduction',
            content: "Understanding late fees › Introduction\n\nText.",
        );

        $this->assertSame('/help/billing/late-fees', $chunk->url());
    }

    public function test_body_drops_the_heading_line_the_content_starts_with(): void
    {
        $this->assertSame('The fee is 1.5%.', $this->chunk()->body());
    }

    public function test_covers_matches_a_golden_label_with_or_without_spaces_around_the_separator(): void
    {
        $this->assertTrue($this->chunk()->covers('late-fees›How the fee is calculated'));
        $this->assertTrue($this->chunk()->covers('late-fees › How the fee is calculated'));
    }

    public function test_covers_matches_a_section_merged_into_the_chunk(): void
    {
        $chunk = new RetrievedChunk(
            id: 1,
            source: 'help/billing/late-fees.md',
            title: 'Understanding late fees',
            headingPath: 'Billing › Understanding late fees › Overview',
            content: "Understanding late fees › Overview\n\nFirst.\n\n## Waiving a late fee\n\nSecond.",
        );

        $this->assertTrue($chunk->covers('late-fees›Waiving a late fee'));
    }

    public function test_covers_rejects_another_article_with_the_same_section(): void
    {
        $this->assertFalse($this->chunk()->covers('disputes›How the fee is calculated'));
    }

    public function test_label_map_numbers_repeated_labels(): void
    {
        $map = LabelMap::for([$this->chunk(), $this->chunk(), $this->chunk()]);

        $this->assertSame([
            'late-fees › How the fee is calculated',
            'late-fees › How the fee is calculated (2)',
            'late-fees › How the fee is calculated (3)',
        ], array_keys($map));
    }

    private function chunk(): RetrievedChunk
    {
        return new RetrievedChunk(
            id: 7,
            source: 'help/billing/late-fees.md',
            title: 'Understanding late fees',
            headingPath: 'Billing › Understanding late fees › How the fee is calculated',
            content: "Understanding late fees › How the fee is calculated\n\nThe fee is 1.5%.",
        );
    }
}
