<?php

namespace Tests\Unit\Ai\Embeddings;

use App\Ai\Embeddings\Chunk;
use App\Ai\Embeddings\ParentChildChunker;
use App\Ai\Tokens\RatioCounter;
use PHPUnit\Framework\TestCase;

class ParentChildChunkerTest extends TestCase
{
    public function test_children_drop_the_label_line_and_keep_the_body(): void
    {
        $children = $this->chunker(200)->children($this->parent(['The fee is 1.5%.', 'A fee under $1.00 is not charged.']));

        $this->assertCount(1, $children);
        $this->assertStringNotContainsString('Late fees › Calculation', $children[0]->text);
        $this->assertStringContainsString('The fee is 1.5%.', $children[0]->text);
        $this->assertStringContainsString('not charged', $children[0]->text);
    }

    public function test_children_split_when_the_paragraphs_exceed_the_budget(): void
    {
        $paragraph = str_repeat('word ', 40);

        $children = $this->chunker(60)->children($this->parent([$paragraph, $paragraph, $paragraph]));

        $this->assertGreaterThan(1, count($children));
        $this->assertSame(range(0, count($children) - 1), array_map(fn ($child): int => $child->index, $children));
    }

    public function test_children_split_an_oversized_paragraph_on_sentences(): void
    {
        $paragraph = implode(' ', array_fill(0, 12, 'This sentence has a handful of words in it.'));

        $children = $this->chunker(40)->children($this->parent([$paragraph]));

        $this->assertGreaterThan(1, count($children));
        $this->assertStringEndsWith('.', $children[0]->text);
    }

    public function test_children_attach_a_merged_subheading_to_the_paragraph_that_follows_it(): void
    {
        $children = $this->chunker(200)->children($this->parent(['First paragraph.', '## Proration', 'Second paragraph.']));

        $this->assertStringContainsString("## Proration\n\nSecond paragraph.", $children[0]->text);
    }

    public function test_children_of_an_empty_body_are_empty(): void
    {
        $this->assertSame([], $this->chunker(200)->children($this->parent([])));
    }

    private function chunker(int $max): ParentChildChunker
    {
        return new ParentChildChunker(new RatioCounter, $max);
    }

    private function parent(array $paragraphs): Chunk
    {
        $content = implode("\n\n", ['Late fees › Calculation', ...$paragraphs]);

        return new Chunk('Late fees › Calculation', $content, 100, 'Calculation');
    }
}
