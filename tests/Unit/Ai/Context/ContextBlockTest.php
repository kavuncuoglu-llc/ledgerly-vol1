<?php

namespace Tests\Unit\Ai\Context;

use App\Ai\Context\ContextBlock;
use App\Ai\Retrieval\RetrievedChunk;
use PHPUnit\Framework\TestCase;

class ContextBlockTest extends TestCase
{
    public function test_render_labels_every_passage_with_slug_and_section_under_a_versioned_preamble(): void
    {
        $block = ContextBlock::render([
            $this->chunk('late-fees', 'How the fee is calculated', 'The fee is 1.5% per month.'),
            $this->chunk('late-fees', 'When a late fee is applied', 'Applied 30 days after the due date.'),
        ]);

        $this->assertSame(
            "The following passages are from Ledgerly's help center (version 7b2e9d0). Answer only from them.\n"
            ."Cite the label of each passage you rely on, in brackets, after the sentence it supports.\n"
            ."If they do not contain the answer, say so.\n\n"
            ."[late-fees › How the fee is calculated]\nThe fee is 1.5% per month.\n\n"
            .'[late-fees › When a late fee is applied]'."\nApplied 30 days after the due date.",
            $block,
        );
    }

    public function test_render_keeps_labels_distinct_when_two_passages_share_one(): void
    {
        $block = ContextBlock::render([
            $this->chunk('late-fees', 'Overview', 'First part.'),
            $this->chunk('late-fees', 'Overview', 'Second part.'),
        ]);

        $this->assertStringContainsString('[late-fees › Overview]', $block);
        $this->assertStringContainsString('[late-fees › Overview (2)]', $block);
    }

    public function test_render_drops_the_heading_line_the_chunk_content_starts_with(): void
    {
        $block = ContextBlock::render([
            $this->chunk('late-fees', 'Overview', 'Body text.'),
        ]);

        $this->assertStringNotContainsString('Understanding late fees › Overview', $block);
    }

    private function chunk(string $slug, string $section, string $body): RetrievedChunk
    {
        return new RetrievedChunk(
            id: 1,
            source: "help/billing/{$slug}.md",
            title: 'Understanding late fees',
            headingPath: "Billing › Understanding late fees › {$section}",
            content: "Understanding late fees › {$section}\n\n{$body}",
            helpVersion: '7b2e9d0',
        );
    }
}
