<?php

namespace App\Ai\Embeddings;

use App\Models\HelpChunk;
use App\Support\HelpArticle;
use App\Support\HelpCenter;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class HelpIndexer
{
    public function __construct(
        private readonly HelpCenter $helpCenter,
        private readonly MarkdownChunker $chunker,
        private readonly Embedder $embedder,
    ) {}

    public function chunks(): Collection
    {
        return $this->helpCenter
            ->all()
            ->flatMap(fn (HelpArticle $article): array => $this->chunksOf($article))
            ->values();
    }

    public function embedAndStore(Collection $chunks, Closure $afterEachEmbedding): void
    {
        $vectors = $chunks->map(function (SourcedChunk $sourced) use ($afterEachEmbedding): array {
            $vector = $this->embedder->document($sourced->chunk->content);

            $afterEachEmbedding($sourced);

            return $vector;
        });

        DB::transaction(function () use ($chunks, $vectors): void {
            $this->deleteStaleChunks($chunks);

            $chunks->each(fn (SourcedChunk $sourced, int $index) => $this->store($sourced, $vectors[$index]));
        });
    }

    private function chunksOf(HelpArticle $article): array
    {
        $chunks = $this->chunker->chunk($article->title, $article->markdown);

        return array_map(
            fn (Chunk $chunk, int $index): SourcedChunk => new SourcedChunk(
                source: $article->path(),
                position: $index + 1,
                chunk: $chunk,
            ),
            $chunks,
            array_keys($chunks),
        );
    }

    private function deleteStaleChunks(Collection $chunks): void
    {
        HelpChunk::query()
            ->whereNotIn('source', $chunks->pluck('source')->unique())
            ->delete();

        $chunks
            ->groupBy('source')
            ->each(fn (Collection $forSource, string $source) => HelpChunk::query()
                ->where('source', $source)
                ->where('position', '>', $forSource->max('position'))
                ->delete());
    }

    private function store(SourcedChunk $sourced, array $vector): void
    {
        HelpChunk::query()->updateOrCreate(
            [
                'source' => $sourced->source,
                'position' => $sourced->position,
            ],
            [
                'heading' => $sourced->chunk->heading,
                'content' => $sourced->chunk->content,
                'tokens' => $sourced->chunk->tokens,
                'embedding' => $vector,
            ],
        );
    }
}
