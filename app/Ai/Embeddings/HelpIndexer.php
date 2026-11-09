<?php

namespace App\Ai\Embeddings;

use App\Models\HelpChunk;
use App\Support\HelpArticle;
use App\Support\HelpCenter;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HelpIndexer
{
    private const PATH_SEPARATOR = ' › ';

    public function __construct(
        private readonly HelpCenter $helpCenter,
        private readonly MarkdownChunker $chunker,
        private readonly Embedder $embedder,
        private readonly BoilerplateStripper $boilerplate,
    ) {}

    public function chunks(?Collection $importedArticles = null): Collection
    {
        return $this->helpCenter
            ->all()
            ->concat($importedArticles ?? collect())
            ->flatMap(fn (HelpArticle $article): array => $this->chunksOf($article))
            ->values();
    }

    public function boilerplatePatterns(): int
    {
        return $this->boilerplate->count();
    }

    public function previousVersion(): string
    {
        return (string) HelpChunk::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('help_version');
    }

    public function diff(Collection $chunks, bool $force = false): IngestDiff
    {
        $stored = HelpChunk::query()
            ->select(['chunk_key', 'content', 'title', 'heading_path', 'tags'])
            ->selectRaw('(embedding IS NULL) AS unembedded')
            ->get()
            ->keyBy('chunk_key');

        $incoming = $chunks->keyBy(fn (SourcedChunk $sourced): string => $sourced->chunkKey());

        $added = $incoming->reject(fn (SourcedChunk $sourced, string $key): bool => $stored->has($key));

        $existing = $incoming->filter(fn (SourcedChunk $sourced, string $key): bool => $stored->has($key));

        [$unchanged, $changed] = $existing->partition(
            fn (SourcedChunk $sourced, string $key): bool => ! $force && $this->sameAsStored($sourced, $stored->get($key)),
        );

        return new IngestDiff(
            added: $added->values(),
            changed: $changed->values(),
            unchanged: $unchanged->values(),
            removedKeys: $stored->keys()->diff($incoming->keys())->values()->all(),
        );
    }

    public function ingest(IngestDiff $diff, string $version, Closure $afterEachEmbedding): void
    {
        $vectors = $diff->toEmbed()->map(function (SourcedChunk $sourced) use ($afterEachEmbedding): array {
            $vector = $this->embedder->document($sourced->chunk->content);

            $afterEachEmbedding($sourced);

            return $vector;
        });

        DB::transaction(function () use ($diff, $version, $vectors): void {
            HelpChunk::query()->whereIn('chunk_key', $diff->removedKeys)->delete();

            $diff->toEmbed()->each(fn (SourcedChunk $sourced, int $index) => $this->store($sourced, $version, $vectors[$index]));

            HelpChunk::query()
                ->whereIn('chunk_key', $diff->unchanged->map(fn (SourcedChunk $sourced): string => $sourced->chunkKey())->all())
                ->where('help_version', '!=', $version)
                ->update(['help_version' => $version]);
        });
    }

    private function sameAsStored(SourcedChunk $sourced, HelpChunk $stored): bool
    {
        if ($stored->unembedded) {
            return false;
        }

        return $stored->content === $sourced->chunk->content
            && $stored->title === $sourced->title
            && $stored->heading_path === $sourced->headingPath
            && ($stored->tags ?? []) === $sourced->tags;
    }

    private function chunksOf(HelpArticle $article): array
    {
        $body = $this->boilerplate->strip($article->markdown);

        $chunks = $this->chunker->chunk($article->title, $body);

        return array_map(
            fn (Chunk $chunk, int $index): SourcedChunk => new SourcedChunk(
                source: $article->path(),
                position: $index + 1,
                chunk: $chunk,
                title: $article->title,
                headingPath: $this->headingPath($article, $chunk),
                tags: $article->tags,
                sourceModifiedAt: $article->modifiedAt,
                articleBody: $body,
            ),
            $chunks,
            array_keys($chunks),
        );
    }

    private function headingPath(HelpArticle $article, Chunk $chunk): string
    {
        return implode(self::PATH_SEPARATOR, [
            Str::headline($article->category),
            $article->title,
            $chunk->section,
        ]);
    }

    private function store(SourcedChunk $sourced, string $version, array $vector): void
    {
        HelpChunk::query()->updateOrCreate(
            [
                'chunk_key' => $sourced->chunkKey(),
            ],
            [
                'source' => $sourced->source,
                'position' => $sourced->position,
                'heading' => $sourced->chunk->heading,
                'content' => $sourced->chunk->content,
                'tokens' => $sourced->chunk->tokens,
                'embedding' => $vector,
                'title' => $sourced->title,
                'heading_path' => $sourced->headingPath,
                'tags' => $sourced->tags,
                'help_version' => $version,
                'source_modified_at' => $sourced->sourceModifiedAt ?? now(),
                'kind' => 'parent',
                'parent_id' => null,
                'context_header' => null,
            ],
        );
    }
}
