<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\HelpIndexer;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class HelpEmbed extends Command
{
    protected $signature = 'help:embed';

    protected $description = 'Chunk the help center, embed every chunk, and store the vectors in help_chunks';

    public function handle(HelpIndexer $indexer, Embedder $embedder): int
    {
        $chunks = $indexer->chunks();

        $this->info($this->chunkingSummary($chunks));
        $this->info("Embedding with {$embedder->model()}...");

        $started = microtime(true);

        try {
            $this->embedWithProgress($indexer, $chunks);
        } catch (ModelRequestFailed|EmbeddingFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $seconds = (int) round(microtime(true) - $started);

        $this->info("{$chunks->count()}/{$chunks->count()} ({$seconds}s)");
        $this->info('Stored in help_chunks.');

        return Command::SUCCESS;
    }

    private function embedWithProgress(HelpIndexer $indexer, Collection $chunks): void
    {
        $bar = $this->output->createProgressBar($chunks->count());

        $indexer->embedAndStore($chunks, fn () => $bar->advance());

        $bar->finish();

        $this->newLine();
    }

    private function chunkingSummary(Collection $chunks): string
    {
        $articles = $chunks->pluck('source')->unique()->count();

        $average = (int) round($chunks->avg(fn ($sourced): int => $sourced->chunk->tokens));

        return "Chunking {$articles} articles... {$chunks->count()} chunks (avg {$average} tokens)";
    }
}
