<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Models\HelpChunk;
use Illuminate\Console\Command;

class HelpSearch extends Command
{
    protected $signature = 'help:search
        {question : What a customer might ask}
        {--limit=5 : How many chunks to print}';

    protected $description = 'Embed a question and print the nearest help-center chunks with their cosine similarity';

    public function handle(Embedder $embedder): int
    {
        if (! HelpChunk::query()->exists()) {
            $this->error('help_chunks is empty. Run php artisan help:embed first.');

            return Command::FAILURE;
        }

        try {
            $vector = $embedder->query((string) $this->argument('question'));
        } catch (ModelRequestFailed|EmbeddingFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->printNearest($vector);

        return Command::SUCCESS;
    }

    private function printNearest(array $vector): void
    {
        $nearest = HelpChunk::query()
            ->nearestTo($vector, max(1, (int) $this->option('limit')))
            ->get();

        foreach ($nearest as $chunk) {
            $similarity = number_format((float) $chunk->similarity, 2);

            $this->line("{$similarity} {$chunk->source} › {$chunk->heading}");
        }
    }
}
