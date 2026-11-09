<?php

namespace App\Console\Commands;

use App\Ai\Cache\HelpVersion;
use App\Ai\Embeddings\HelpIndexer;
use App\Ai\Embeddings\ParentChildIndexer;
use App\Ai\Embeddings\ParentChildPlan;
use App\Ai\Exceptions\ContextGenerationFailed;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\SidecarFailed;
use App\Ai\Exceptions\UnsupportedDocument;
use App\Ai\Parsing\ImportedDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class HelpIngest extends Command
{
    private const STRATEGIES = [
        'heading',
        'parent-child',
    ];

    protected $signature = 'help:ingest
        {--docs-version= : The docs version stored on every chunk (default: a fingerprint of resources/help)}
        {--force : Re-embed every chunk, not only the ones that changed}
        {--strategy= : heading or parent-child (default: config ai.chunking.strategy)}
        {--context-headers : Add a model-written one-sentence context to every child chunk header (parent-child only)}
        {--source= : A directory of extra .md, .html, .docx and .pdf files to parse and index as the imports category}';

    protected $description = 'Parse and chunk the help center (heading or parent-child), and embed only the chunks that changed';

    public function handle(HelpIndexer $indexer, ParentChildIndexer $parentChild, ImportedDocuments $imports, HelpVersion $helpVersion): int
    {
        $strategy = (string) ($this->option('strategy') ?: config('ai.chunking.strategy'));

        if (! in_array($strategy, self::STRATEGIES, true)) {
            $this->error("Unknown strategy [{$strategy}]. Use heading or parent-child.");

            return Command::FAILURE;
        }

        if ($this->option('context-headers') && $strategy !== 'parent-child') {
            $this->error('--context-headers needs --strategy=parent-child.');

            return Command::FAILURE;
        }

        $version = (string) ($this->option('docs-version') ?: $helpVersion->current());

        $previous = $indexer->previousVersion();

        $this->info("Stripping boilerplate ({$indexer->boilerplatePatterns()} patterns)...");

        try {
            $chunks = $indexer->chunks($this->importedArticles($imports));
        } catch (SidecarFailed|UnsupportedDocument $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info($this->chunkingSummary($chunks));

        $succeeded = $strategy === 'parent-child'
            ? $this->ingestParentChild($parentChild, $chunks, $version, $previous)
            : $this->ingestHeadings($indexer, $chunks, $version, $previous);

        if (! $succeeded) {
            return Command::FAILURE;
        }

        $this->info("Upserted. help_version = {$version}");

        return Command::SUCCESS;
    }

    private function importedArticles(ImportedDocuments $imports): Collection
    {
        $source = $this->option('source');

        if ($source === null) {
            return collect();
        }

        if (! File::isDirectory((string) $source)) {
            throw UnsupportedDocument::unreadable((string) $source, 'it is not a directory');
        }

        $articles = $imports->articlesIn((string) $source);

        $this->info("Parsed {$articles->count()} imported documents from {$source}");

        return $articles;
    }

    private function ingestHeadings(HelpIndexer $indexer, Collection $chunks, string $version, string $previous): bool
    {
        $diff = $indexer->diff($chunks, (bool) $this->option('force'));

        $this->info($this->diffSummary($diff->changed->count(), $diff->added->count(), $diff->removedCount(), $diff->unchanged->count(), $previous));

        return $this->embed($diff->toEmbed()->count(), fn () => $indexer->ingest($diff, $version, fn () => null));
    }

    private function ingestParentChild(ParentChildIndexer $indexer, Collection $parents, string $version, string $previous): bool
    {
        $withContext = (bool) $this->option('context-headers');

        $plan = $indexer->plan($parents, $withContext, (bool) $this->option('force'));

        $this->info($this->parentChildSummary($plan, $parents));

        $this->info($this->diffSummary($plan->changed->count(), $plan->added->count(), count($plan->removedKeys), $plan->unchanged->count(), $previous));

        return $this->embed($plan->toEmbed()->count(), fn () => $indexer->ingest($plan, $version, fn () => null));
    }

    private function embed(int $toEmbed, callable $ingest): bool
    {
        $this->info("Embedding {$toEmbed} chunks...");

        $started = microtime(true);

        try {
            $ingest();
        } catch (ModelRequestFailed|EmbeddingFailed|ContextGenerationFailed $exception) {
            $this->error($exception->getMessage());

            return false;
        }

        $seconds = number_format(microtime(true) - $started, 1);

        $this->info("Embedded {$toEmbed} chunks ({$seconds}s)");

        return true;
    }

    private function chunkingSummary(Collection $chunks): string
    {
        $articles = $chunks->pluck('source')->unique()->count();

        $average = (int) round($chunks->avg(fn ($sourced): int => $sourced->chunk->tokens));

        return "Chunking {$articles} articles... {$chunks->count()} chunks (avg {$average} tokens)";
    }

    private function parentChildSummary(ParentChildPlan $plan, Collection $parents): string
    {
        return "Parent-child: {$parents->count()} parents (stored, sent to the model) -> {$plan->childCount()} children (embedded, matched; avg {$plan->averageChildTokens()} tokens)";
    }

    private function diffSummary(int $changed, int $added, int $removed, int $unchanged, string $previous): string
    {
        $against = $previous === ''
            ? 'an empty index'
            : $previous;

        return "Diff vs {$against}: {$changed} changed, {$added} new, {$removed} removed, {$unchanged} unchanged";
    }
}
