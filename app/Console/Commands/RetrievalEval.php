<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnreadableGoldenSet;
use App\Ai\Retrieval\EvalReport;
use App\Ai\Retrieval\GoldenSet;
use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\RetrievalEvaluator;
use App\Ai\Retrieval\RetrievalMode;
use App\Ai\Retrieval\RetrievalSettings;
use App\Ai\Retrieval\Retriever;
use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ValueError;

class RetrievalEval extends Command
{
    protected $signature = 'retrieval:eval
        {--golden= : The JSONL golden set (default: config ai.retrieval.golden_set)}
        {--mode= : hybrid, vector or keyword (default: config ai.retrieval.mode)}
        {--floor= : Similarity floor override}';

    protected $description = 'Score retrieval alone against the golden set: recall, precision, MRR and the off-topic questions';

    public function handle(Retriever $retriever, RetrievalEvaluator $evaluator, Embedder $embedder): int
    {
        try {
            $questions = GoldenSet::fromFile($this->goldenPath());

            $settings = $this->settings();
        } catch (UnreadableGoldenSet|ValueError $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (! $retriever instanceof PgRetriever) {
            $this->error('retrieval:eval needs the pgvector retriever.');

            return Command::FAILURE;
        }

        if (! HelpChunk::query()->exists()) {
            $this->error('help_chunks is empty. Run php artisan help:ingest first.');

            return Command::FAILURE;
        }

        try {
            $report = $evaluator->evaluate($retriever->using($settings), $questions);
        } catch (ModelRequestFailed|EmbeddingFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->printReport($report, $settings, $embedder->model());

        return Command::SUCCESS;
    }

    private function goldenPath(): string
    {
        $path = (string) ($this->option('golden') ?: config('ai.retrieval.golden_set'));

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function settings(): RetrievalSettings
    {
        $base = RetrievalSettings::fromConfig();

        $mode = $this->option('mode');

        $floor = $this->option('floor');

        return $base->with(
            mode: $mode === null
                ? null
                : RetrievalMode::from((string) $mode),
            similarityFloor: $floor === null
                ? null
                : (float) $floor,
        );
    }

    private function printReport(EvalReport $report, RetrievalSettings $settings, string $model): void
    {
        $version = (string) HelpChunk::query()->orderByDesc('updated_at')->value('help_version');

        $this->line(sprintf(
            'config: %s · threshold %.2f · k %d · rrf %d · %s · chunks by heading · help %s',
            $settings->mode->value,
            $settings->similarityFloor,
            $settings->k,
            $settings->rrfConstant,
            $model,
            $version,
        ));

        $this->line(sprintf('%-4s%8s%11s%7s', '', 'recall', 'precision', 'MRR'));

        foreach (RetrievalEvaluator::CUTOFFS as $k) {
            $mrr = $k === max(RetrievalEvaluator::CUTOFFS)
                ? sprintf('%7.2f', $report->mrr)
                : '';

            $this->line(sprintf('@%-3d%8.2f%11.2f%s', $k, $report->recall[$k], $report->precision[$k], $mrr));
        }

        $this->line("empty-expected correct: {$report->emptyCorrect}/{$report->emptyTotal}");

        $this->reportMisses($report);

        $this->compareWithPrevious($report);

        $this->store($report, $settings, $version);
    }

    private function reportMisses(EvalReport $report): void
    {
        $count = count($report->misses);

        $ids = implode(' ', array_map(fn (array $miss): string => '#'.$miss['id'], array_slice($report->misses, 0, 12)));

        $file = 'golden/misses-'.now()->format('Y-m-d').'.json';

        File::ensureDirectoryExists(storage_path('golden'));

        File::put(storage_path($file), json_encode($report->misses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

        $this->line("misses ({$count}): {$ids} → storage/{$file}");
    }

    private function compareWithPrevious(EvalReport $report): void
    {
        $previous = $this->previousResult();

        if ($previous === []) {
            return;
        }

        $before = (float) $previous['recall'][5];

        $after = $report->recall[5];

        $this->line(sprintf(
            'vs %s (%s): recall@5 %.2f → %.2f (%+.2f)',
            $previous['date'],
            $previous['mode'],
            $before,
            $after,
            $after - $before,
        ));
    }

    private function previousResult(): array
    {
        $files = File::glob(storage_path('golden/results-*.json'));

        if ($files === []) {
            return [];
        }

        sort($files);

        return json_decode(File::get(end($files)), true) ?? [];
    }

    private function store(EvalReport $report, RetrievalSettings $settings, string $version): void
    {
        $stamp = now()->format('Y-m-d\THis');

        File::put(storage_path("golden/results-{$stamp}.json"), json_encode([
            'date' => $stamp,
            'mode' => $settings->mode->value,
            'floor' => $settings->similarityFloor,
            'k' => $settings->k,
            'help_version' => $version,
            ...$report->toArray(),
        ], JSON_PRETTY_PRINT)."\n");
    }
}
