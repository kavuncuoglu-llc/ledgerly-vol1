<?php

namespace Tests\Unit\Ai\Embeddings;

use App\Ai\Embeddings\MarkdownChunker;
use App\Ai\Tokens\RatioCounter;
use PHPUnit\Framework\TestCase;

class MarkdownChunkerTest extends TestCase
{
    public function test_chunk_keeps_title_and_heading_in_each_chunk(): void
    {
        $chunks = $this->chunker(min: 1, max: 400)->chunk('Late fees', $this->article());

        $this->assertCount(2, $chunks);
        $this->assertSame('Late fees › When a fee applies', $chunks[0]->heading);
        $this->assertStringStartsWith("Late fees › When a fee applies\n\n", $chunks[0]->content);
        $this->assertSame('Late fees › How it is calculated', $chunks[1]->heading);
    }

    public function test_chunk_drops_the_title_line_from_the_body(): void
    {
        $chunks = $this->chunker(min: 1, max: 400)->chunk('Late fees', $this->article());

        $this->assertStringNotContainsString('# Late fees', $chunks[0]->content);
    }

    public function test_chunk_labels_text_before_the_first_heading_as_introduction(): void
    {
        $markdown = "# Late fees\n\nIntro paragraph.\n\n## Details\n\nBody.";

        $chunks = $this->chunker(min: 1, max: 400)->chunk('Late fees', $markdown);

        $this->assertSame('Late fees › Introduction', $chunks[0]->heading);
    }

    public function test_chunk_splits_an_oversized_section_on_paragraph_boundaries(): void
    {
        $paragraph = str_repeat('word ', 40);
        $markdown = "# Title\n\n## Long\n\n{$paragraph}\n\n{$paragraph}\n\n{$paragraph}";

        $chunks = $this->chunker(min: 1, max: 60)->chunk('Title', $markdown);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertSame('Title › Long', $chunk->heading);
            $this->assertLessThanOrEqual(60, $chunk->tokens);
        }
    }

    public function test_chunk_merges_a_small_section_into_the_next_one_when_it_fits(): void
    {
        $markdown = "# Title\n\n## First\n\nShort.\n\n## Second\n\nAlso short.";

        $chunks = $this->chunker(min: 200, max: 400)->chunk('Title', $markdown);

        $this->assertCount(1, $chunks);
        $this->assertSame('Title › First', $chunks[0]->heading);
        $this->assertStringContainsString("## Second\n\nAlso short.", $chunks[0]->content);
    }

    public function test_chunk_does_not_split_on_headings_inside_code_fences(): void
    {
        $markdown = "# Title\n\n## Example\n\n```\n## not a heading\n```";

        $chunks = $this->chunker(min: 1, max: 400)->chunk('Title', $markdown);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('## not a heading', $chunks[0]->content);
    }

    public function test_chunk_reports_the_token_count_of_the_chunk_content(): void
    {
        $chunks = $this->chunker(min: 1, max: 400)->chunk('Late fees', $this->article());

        $this->assertSame((new RatioCounter)->count($chunks[0]->content), $chunks[0]->tokens);
    }

    public function test_chunk_returns_no_chunks_for_an_empty_article(): void
    {
        $this->assertSame([], $this->chunker(min: 1, max: 400)->chunk('Empty', "# Empty\n"));
    }

    private function chunker(int $min, int $max): MarkdownChunker
    {
        return new MarkdownChunker(new RatioCounter, $min, $max);
    }

    private function article(): string
    {
        return "# Late fees\n\n## When a fee applies\n\nThirty days after the due date.\n\n## How it is calculated\n\n1.5% per month, prorated by day.";
    }
}
