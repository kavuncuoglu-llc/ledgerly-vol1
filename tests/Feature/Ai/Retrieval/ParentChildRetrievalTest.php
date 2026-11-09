<?php

namespace Tests\Feature\Ai\Retrieval;

use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Models\HelpChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class ParentChildRetrievalTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    private const QUESTION = 'is there a minimum late fee?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableVectorIndexScans();
    }

    public function test_retrieve_matches_the_child_and_returns_the_parent_section(): void
    {
        $parent = $this->seedParent('late-fees', 'How the fee is calculated', 'The fee is 1.5%. A fee under $1.00 is not charged.');
        $this->seedChild($parent, 'A fee under $1.00 is not charged.', $this->blendedVector(0, 1, 0.95), index: 0);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope);

        $this->assertCount(1, $chunks);
        $this->assertSame($parent->id, $chunks[0]->id);
        $this->assertStringContainsString('The fee is 1.5%.', $chunks[0]->content);
        $this->assertStringContainsString('A fee under $1.00 is not charged.', $chunks[0]->matchText());
        $this->assertStringStartsWith('late-fees › How the fee is calculated', $chunks[0]->matchText());
        $this->assertEqualsWithDelta(0.95, $chunks[0]->similarity, 0.001);
    }

    public function test_retrieve_collapses_children_of_one_parent_into_one_result(): void
    {
        $parent = $this->seedParent('late-fees', 'How the fee is calculated', 'Both halves.');
        $this->seedChild($parent, 'First half.', $this->blendedVector(0, 1, 0.90), index: 0);
        $this->seedChild($parent, 'Second half.', $this->blendedVector(0, 2, 0.85), index: 1);
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve(self::QUESTION, new RetrievalScope);

        $this->assertCount(1, $chunks);
        $this->assertEqualsWithDelta(0.90, $chunks[0]->similarity, 0.001);
    }

    public function test_retrieve_never_returns_a_parent_that_has_no_embedding(): void
    {
        $this->seedParent('late-fees', 'Overview', 'Parent text that mentions the late fee.');
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)]);

        $chunks = app(Retriever::class)->retrieve('late fee', new RetrievalScope);

        $this->assertSame([], $chunks);
    }

    public function test_retrieve_finds_a_child_through_its_context_header_in_the_keyword_index(): void
    {
        $parent = $this->seedParent('late-fees', 'Proration', 'Daily rate.');
        $this->seedChild($parent, 'The daily rate is the monthly rate divided by 30.', $this->blendedVector(0, 1, 0.10), index: 0, header: 'late-fees › Proration — how annual plan fees are prorated');
        $this->fakeEmbeddingsAndChat(['annual prorated' => $this->axisVector(5)]);

        $chunks = app(Retriever::class)->retrieve('annual prorated', new RetrievalScope);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->keywordMatched);
        $this->assertSame($parent->id, $chunks[0]->id);
    }

    public function test_deleting_a_parent_deletes_its_children(): void
    {
        $parent = $this->seedParent('late-fees', 'Overview', 'Text.');
        $this->seedChild($parent, 'Child.', $this->axisVector(0), index: 0);

        $parent->delete();

        $this->assertSame(0, HelpChunk::query()->count());
    }

    public function test_nearest_to_skips_parents_without_an_embedding(): void
    {
        $parent = $this->seedParent('late-fees', 'Overview', 'Text.');
        $this->seedChild($parent, 'Child.', $this->axisVector(0), index: 0);

        $nearest = HelpChunk::query()->nearestTo($this->axisVector(0), 5)->get();

        $this->assertCount(1, $nearest);
        $this->assertSame('Child.', $nearest[0]->content);
    }

    private function seedParent(string $slug, string $section, string $body): HelpChunk
    {
        return HelpChunk::query()->create([
            'source' => "help/billing/{$slug}.md",
            'position' => 1,
            'heading' => "{$slug} › {$section}",
            'content' => "{$slug} › {$section}\n\n{$body}",
            'tokens' => 50,
            'embedding' => null,
            'title' => $slug,
            'heading_path' => "Billing › {$slug} › {$section}",
            'tags' => ['area' => 'billing'],
            'help_version' => 'v1',
            'source_modified_at' => now(),
            'kind' => 'parent',
        ]);
    }

    private function seedChild(HelpChunk $parent, string $text, array $vector, int $index, ?string $header = null): HelpChunk
    {
        return HelpChunk::query()->create([
            'source' => $parent->source,
            'position' => 1000 + $index + 1,
            'heading' => $parent->heading,
            'content' => $text,
            'tokens' => 20,
            'embedding' => $vector,
            'title' => $parent->title,
            'heading_path' => $parent->heading_path,
            'tags' => $parent->tags,
            'help_version' => 'v1',
            'source_modified_at' => now(),
            'kind' => 'child',
            'parent_id' => $parent->id,
            'context_header' => $header ?? $parent->heading,
        ]);
    }
}
