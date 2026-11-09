<?php

namespace App\Ai\Embeddings;

use App\Ai\Retrieval\ContextualizeChunks;
use App\Models\HelpChunk;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ParentChildIndexer
{
    public function __construct(
        private readonly ParentChildChunker $chunker,
        private readonly Embedder $embedder,
        private readonly ContextualizeChunks $contexts,
    ) {}

    public function plan(Collection $parents, bool $contextHeaders, bool $force = false): ParentChildPlan
    {
        $stored = HelpChunk::query()
            ->where('kind', 'child')
            ->get(['chunk_key', 'content', 'title', 'heading_path', 'tags', 'context_header'])
            ->keyBy('chunk_key');

        $units = $this->units($parents, $stored, $contextHeaders)->keyBy(fn (ChildUnit $unit): string => $unit->chunkKey());

        $existing = $units->filter(fn (ChildUnit $unit, string $key): bool => $stored->has($key));

        [$unchanged, $changed] = $existing->partition(
            fn (ChildUnit $unit, string $key): bool => ! $force && $this->sameAsStored($unit, $stored->get($key)),
        );

        return new ParentChildPlan(
            parents: $parents,
            added: $units->reject(fn (ChildUnit $unit, string $key): bool => $stored->has($key))->values(),
            changed: $changed->values(),
            unchanged: $unchanged->values(),
            removedKeys: $this->removedKeys($units, $parents),
        );
    }

    public function ingest(ParentChildPlan $plan, string $version, Closure $afterEachEmbedding): void
    {
        $embedded = $plan->toEmbed()
            ->chunk(max(1, (int) config('ai.embedding.batch_size')))
            ->flatMap(fn (Collection $batch): array => $this->embedBatch($batch, $afterEachEmbedding))
            ->values();

        DB::transaction(function () use ($plan, $version, $embedded): void {
            HelpChunk::query()->whereIn('chunk_key', $plan->removedKeys)->delete();

            $parentIds = $plan->parents->mapWithKeys(fn (SourcedChunk $parent): array => [$parent->chunkKey() => $this->storeParent($parent, $version)]);

            $plan->toEmbed()->each(fn (ChildUnit $unit, int $index) => $this->storeChild($unit, $version, $parentIds[$unit->parent->chunkKey()], $embedded[$index]));

            HelpChunk::query()
                ->whereIn('chunk_key', $plan->unchanged->map(fn (ChildUnit $unit): string => $unit->chunkKey())->all())
                ->where('help_version', '!=', $version)
                ->update(['help_version' => $version]);
        });
    }

    private function embedBatch(Collection $batch, Closure $afterEachEmbedding): array
    {
        $headers = $batch
            ->map(fn (ChildUnit $unit): string => $unit->reusableHeader ?? $this->generatedHeader($unit))
            ->values();

        $vectors = $this->embedder->documents(
            $batch->values()->map(fn (ChildUnit $unit, int $index): string => $unit->withHeader($headers[$index]))->all(),
        );

        $batch->each(fn (ChildUnit $unit) => $afterEachEmbedding($unit));

        return $headers
            ->map(fn (string $header, int $index): array => [
                'header' => $header,
                'vector' => $vectors[$index],
            ])
            ->all();
    }

    private function units(Collection $parents, Collection $stored, bool $contextHeaders): Collection
    {
        return $parents->flatMap(fn (SourcedChunk $parent): array => array_map(
            fn (ChildChunk $child): ChildUnit => new ChildUnit(
                parent: $parent,
                child: $child,
                reusableHeader: $this->reusableHeader($parent, $child, $stored, $contextHeaders),
            ),
            $this->chunker->children($parent->chunk),
        ))->values();
    }

    private function reusableHeader(SourcedChunk $parent, ChildChunk $child, Collection $stored, bool $contextHeaders): ?string
    {
        if (! $contextHeaders) {
            return $parent->chunk->heading;
        }

        $key = sha1($parent->chunkKey().':c'.$child->index);

        $previous = $stored->get($key);

        if ($previous === null) {
            return null;
        }

        if ($previous->content !== $child->text) {
            return null;
        }

        if (! str_starts_with((string) $previous->context_header, $parent->chunk->heading.ChildUnit::HEADER_SEPARATOR)) {
            return null;
        }

        return $previous->context_header;
    }

    private function sameAsStored(ChildUnit $unit, HelpChunk $stored): bool
    {
        if ($unit->reusableHeader === null) {
            return false;
        }

        return $stored->content === $unit->child->text
            && $stored->context_header === $unit->reusableHeader
            && $stored->title === $unit->parent->title
            && $stored->heading_path === $unit->parent->headingPath
            && ($stored->tags ?? []) === $unit->parent->tags;
    }

    private function removedKeys(Collection $units, Collection $parents): array
    {
        $incoming = $units->keys()
            ->concat($parents->map(fn (SourcedChunk $parent): string => $parent->chunkKey()));

        return HelpChunk::query()
            ->pluck('chunk_key')
            ->diff($incoming)
            ->values()
            ->all();
    }

    private function generatedHeader(ChildUnit $unit): string
    {
        $context = $this->contexts->contextFor($unit->parent->articleBody, $unit->child->text);

        return $unit->baseHeader().ChildUnit::HEADER_SEPARATOR.$context;
    }

    private function storeParent(SourcedChunk $parent, string $version): int
    {
        return HelpChunk::query()->updateOrCreate(
            [
                'chunk_key' => $parent->chunkKey(),
            ],
            [
                'source' => $parent->source,
                'position' => $parent->position,
                'heading' => $parent->chunk->heading,
                'content' => $parent->chunk->content,
                'tokens' => $parent->chunk->tokens,
                'embedding' => null,
                'title' => $parent->title,
                'heading_path' => $parent->headingPath,
                'tags' => $parent->tags,
                'help_version' => $version,
                'source_modified_at' => $parent->sourceModifiedAt ?? now(),
                'kind' => 'parent',
                'parent_id' => null,
                'context_header' => null,
            ],
        )->id;
    }

    private function storeChild(ChildUnit $unit, string $version, int $parentId, array $embedded): void
    {
        HelpChunk::query()->updateOrCreate(
            [
                'chunk_key' => $unit->chunkKey(),
            ],
            [
                'source' => $unit->parent->source,
                'position' => $unit->position(),
                'heading' => $unit->parent->chunk->heading,
                'content' => $unit->child->text,
                'tokens' => $unit->child->tokens,
                'embedding' => $embedded['vector'],
                'title' => $unit->parent->title,
                'heading_path' => $unit->parent->headingPath,
                'tags' => $unit->parent->tags,
                'help_version' => $version,
                'source_modified_at' => $unit->parent->sourceModifiedAt ?? now(),
                'kind' => 'child',
                'parent_id' => $parentId,
                'context_header' => $embedded['header'],
            ],
        );
    }
}
