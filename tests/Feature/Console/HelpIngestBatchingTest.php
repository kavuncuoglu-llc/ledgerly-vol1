<?php

namespace Tests\Feature\Console;

use App\Models\HelpChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HelpIngestBatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config()->set('ai.embedding.batch_size', 48);
        $this->fakeBatchedEmbeddings();
    }

    public function test_the_heading_strategy_embeds_in_batches_of_at_most_48(): void
    {
        $this->artisan('help:ingest', ['--docs-version' => 'v1', '--strategy' => 'heading'])->assertSuccessful();

        $chunks = HelpChunk::query()->count();
        $sizes = $this->requestSizes();
        $this->assertSame($chunks, array_sum($sizes));
        $this->assertSame((int) ceil($chunks / 48), count($sizes));
        $this->assertLessThanOrEqual(48, max($sizes));
    }

    public function test_every_chunk_gets_the_vector_that_belongs_to_its_text(): void
    {
        $this->artisan('help:ingest', ['--docs-version' => 'v1', '--strategy' => 'heading'])->assertSuccessful();

        $chunk = HelpChunk::query()->orderBy('id')->get()->random();

        $this->assertSame($this->vectorFor('search_document: '.$chunk->content), array_map('floatval', $chunk->embedding));
    }

    public function test_the_parent_child_strategy_embeds_only_children_and_in_batches(): void
    {
        $this->artisan('help:ingest', ['--docs-version' => 'v1', '--strategy' => 'parent-child'])->assertSuccessful();

        $children = HelpChunk::query()->where('kind', 'child')->count();
        $sizes = $this->requestSizes();
        $this->assertSame($children, array_sum($sizes));
        $this->assertLessThanOrEqual(48, max($sizes));
        $this->assertGreaterThan(count($sizes), $children);
    }

    public function test_a_batch_size_of_one_sends_one_request_per_chunk(): void
    {
        config()->set('ai.embedding.batch_size', 1);

        $this->artisan('help:ingest', ['--docs-version' => 'v1', '--strategy' => 'heading'])->assertSuccessful();

        $this->assertCount(HelpChunk::query()->count(), $this->requestSizes());
    }

    private function requestSizes(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): int => is_array($pair[0]['input'])
                ? count($pair[0]['input'])
                : 1)
            ->all();
    }

    private function fakeBatchedEmbeddings(): void
    {
        Http::fake(function (Request $request) {
            $inputs = (array) $request['input'];

            return Http::response([
                'data' => array_map(
                    fn (string $input, int $index): array => [
                        'index' => $index,
                        'embedding' => $this->vectorFor($input),
                    ],
                    $inputs,
                    array_keys($inputs),
                ),
            ]);
        });
    }

    private function vectorFor(string $input): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[crc32($input) % 768] = 1.0;

        return $vector;
    }
}
