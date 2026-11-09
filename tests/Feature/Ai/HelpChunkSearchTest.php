<?php

namespace Tests\Feature\Ai;

use App\Models\HelpChunk;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpChunkSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_nearest_to_orders_chunks_by_cosine_similarity_with_the_closest_first(): void
    {
        $this->chunk(position: 1, vector: $this->unitVector(0));
        $this->chunk(position: 2, vector: $this->blend(0, 1));
        $this->chunk(position: 3, vector: $this->unitVector(1));

        $nearest = HelpChunk::query()->nearestTo($this->unitVector(0), 3)->get();

        $this->assertSame([1, 2, 3], $nearest->pluck('position')->all());
        $this->assertEqualsWithDelta(1.0, $nearest[0]->similarity, 0.0001);
        $this->assertEqualsWithDelta(0.7071, $nearest[1]->similarity, 0.0001);
        $this->assertEqualsWithDelta(0.0, $nearest[2]->similarity, 0.0001);
    }

    public function test_nearest_to_honours_the_limit(): void
    {
        $this->chunk(position: 1, vector: $this->unitVector(0));
        $this->chunk(position: 2, vector: $this->unitVector(1));

        $nearest = HelpChunk::query()->nearestTo($this->unitVector(0), 1)->get();

        $this->assertCount(1, $nearest);
    }

    public function test_embedding_round_trips_through_the_vector_cast(): void
    {
        $this->chunk(position: 1, vector: $this->blend(2, 3));

        $stored = HelpChunk::query()->sole();

        $this->assertCount(768, $stored->embedding);
        $this->assertEqualsWithDelta(0.7071, $stored->embedding[2], 0.0001);
    }

    public function test_help_chunks_rejects_a_duplicate_source_and_position(): void
    {
        $this->chunk(position: 1, vector: $this->unitVector(0));

        $this->expectException(QueryException::class);

        $this->chunk(position: 1, vector: $this->unitVector(1));
    }

    private function chunk(int $position, array $vector): HelpChunk
    {
        return HelpChunk::query()->create([
            'source' => 'help/billing/late-fees.md',
            'position' => $position,
            'heading' => 'Understanding late fees › Overview',
            'content' => 'Understanding late fees › Overview',
            'tokens' => 10,
            'embedding' => $vector,
        ]);
    }

    private function unitVector(int $index): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$index] = 1.0;

        return $vector;
    }

    private function blend(int $first, int $second): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$first] = M_SQRT1_2;
        $vector[$second] = M_SQRT1_2;

        return $vector;
    }
}
