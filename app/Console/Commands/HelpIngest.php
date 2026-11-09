<?php

namespace App\Console\Commands;

use App\Ai\Cache\HelpVersion;
use App\Ai\Embeddings\HelpIndexer;
use App\Ai\Embeddings\IngestDiff;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class HelpIngest extends Command
{
    protected $signature = 'help:ingest
        {--docs-version= : The docs version stored on every chunk (default: a fingerprint of resources/help)}
        {--force : Re-embed every chunk, not only the ones that changed}';

    protected $description = 'Strip boilerplate, chunk the help center with metadata, and upsert only the chunks that changed';

    public function handle(HelpIndexer $indexer, HelpVersion $helpVersion): int
    {
        $version = (string) ($this->option('docs-version') ?: $helpVersion->current());

        $previous = $indexer->previousVersion();

        $this->info("Stripping boilerplate ({$indexer->boilerplatePatterns()} patterns)...");

        $chunks = $indexer->chunks();

        $this->info($this->chunkingSummary($chunks));

        $diff = $indexer->diff($chunks, (bool) $this->option('force'));

        $this->info($this->diffSummary($diff, $previous));

        if (! $this->embedAndUpsert($indexer, $diff, $version)) {
            return Command::FAILURE;
        }

        $this->info("Upserted. help_version = {$version}");

        return Command::SUCCESS;
    }

    private function embedAndUpsert(HelpIndexer $indexer, IngestDiff $diff, string $version): bool
    {
        $toEmbed = $diff->toEmbed()->count();

        $this->info("Embedding {$toEmbed} chunks...");

        $started = microtime(true);

        try {
            $indexer->ingest($diff, $version, fn () => null);
        } catch (ModelRequestFailed|EmbeddingFailed $exception) {
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

    private function diffSummary(IngestDiff $diff, string $previous): string
    {
        $against = $previous === ''
            ? 'an empty index'
            : $previous;

        return "Diff vs {$against}: {$diff->changed->count()} changed, {$diff->added->count()} new, {$diff->removedCount()} removed, {$diff->unchanged->count()} unchanged";
    }
}
