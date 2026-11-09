<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\InvalidIndexTable;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\SidecarFailed;
use App\Ai\Exceptions\UnreadableGoldenSet;
use App\Ai\Exceptions\UnsupportedReranker;
use App\Ai\Http\AiHttpClient;
use App\Ai\Retrieval\CandidateIndex;
use App\Ai\Retrieval\EvalReport;
use App\Ai\Retrieval\GoldenSet;
use App\Ai\Retrieval\RetrievalEvaluator;
use App\Ai\Retrieval\RetrievalMode;
use App\Ai\Retrieval\RetrievalSettings;
use App\Ai\Retrieval\RetrieverFactory;
use App\Ai\Retrieval\RewriteMode;
use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ValueError;

class RetrievalEval extends Command
{
    protected $signature = 'retrieval:eval
        {--golden= : The JSONL golden set (default: config ai.retrieval.golden_set)}
        {--mode= : hybrid, vector or keyword (default: config ai.retrieval.mode)}
        {--floor= : Similarity floor override}
        {--rerank= : none, sidecar or lexical (default: config ai.retrieval.reranker)}
        {--rewrite= : off, rewrite, expand or hyde (default: config ai.retrieval.rewrite)}
        {--candidates= : Stage-one candidates handed to the reranker (default: config ai.retrieval.rerank_candidates)}
        {--steps : Print stage one, plus rerank, plus query rewrite as one table}
        {--embed-model= : Evaluate another embedding model (needs --reindex-to)}
        {--embed-dimensions= : Its vector size (default: probed from the model)}
        {--reindex-to= : Re-embed the index into this bench_* table, evaluate it, then drop it}
        {--keep-index : Keep the bench_* table after the run}';

    protected $description = 'Score retrieval alone against the golden set: recall, precision, MRR and the off-topic questions';

    public function handle(RetrieverFactory $factory, RetrievalEvaluator $evaluator, Embedder $embedder, CandidateIndex $candidateIndex, AiHttpClient $client): int
    {
        try {
            $questions = GoldenSet::fromFile($this->goldenPath());

            $settings = $this->settings();

            $rewrite = $this->rewriteMode();

            $reranker = $this->rerankerDriver();

            $factory->rerankerFor($reranker);
        } catch (UnreadableGoldenSet|ValueError|UnsupportedReranker $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (! HelpChunk::query()->exists()) {
            $this->error('help_chunks is empty. Run php artisan help:ingest first.');

            return Command::FAILURE;
        }

        try {
            return $this->evaluateWith($factory, $evaluator, $embedder, $candidateIndex, $client, $questions, $settings, $rewrite, $reranker);
        } catch (ModelRequestFailed|EmbeddingFailed|SidecarFailed|InvalidIndexTable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function evaluateWith(RetrieverFactory $factory, RetrievalEvaluator $evaluator, Embedder $embedder, CandidateIndex $candidateIndex, AiHttpClient $client, array $questions, RetrievalSettings $settings, RewriteMode $rewrite, string $reranker): int
    {
        if ($this->option('reindex-to') !== null) {
            return $this->compareCandidate($factory, $evaluator, $candidateIndex, $client, $questions, $settings, $reranker);
        }

        if ($this->option('steps')) {
            $this->printSteps($factory, $evaluator, $questions, $settings, $rewrite, $reranker);

            return Command::SUCCESS;
        }

        $retriever = $factory->make($settings, $rewrite, $reranker, $this->candidates());

        $report = $evaluator->evaluate($retriever, $questions);

        $this->printReport($report, $settings, $embedder->model(), $rewrite, $reranker);

        return Command::SUCCESS;
    }

    private function printSteps(RetrieverFactory $factory, RetrievalEvaluator $evaluator, array $questions, RetrievalSettings $settings, RewriteMode $rewrite, string $reranker): void
    {
        $strategy = $this->strategy();

        $candidates = $this->candidates();

        $rewriting = $rewrite === RewriteMode::Off
            ? RewriteMode::Rewrite
            : $rewrite;

        $this->line(sprintf('%-52s%8s%13s%7s%16s%10s', 'step', 'recall@5', 'precision@5', 'MRR', 'empty-expected', 'query ms'));

        $this->stepRow('stage one (hybrid, '.$strategy.' chunks)', $factory, $evaluator, $questions, $settings, RewriteMode::Off, 'none', $candidates);

        if ($reranker === 'none') {
            $this->line('+ rerank: skipped (reranker is none; use --rerank=sidecar)');
        }

        if ($reranker !== 'none') {
            $this->stepRow("+ rerank ({$reranker}, top {$candidates})", $factory, $evaluator, $questions, $settings, RewriteMode::Off, $reranker, $candidates);
        }

        $this->stepRow("+ query {$rewriting->value}", $factory, $evaluator, $questions, $settings, $rewriting, $reranker, $candidates);
    }

    private function stepRow(string $label, RetrieverFactory $factory, RetrievalEvaluator $evaluator, array $questions, RetrievalSettings $settings, RewriteMode $rewrite, string $reranker, int $candidates): void
    {
        try {
            $report = $evaluator->evaluate($factory->make($settings, $rewrite, $reranker, $candidates), $questions);
        } catch (ModelRequestFailed|EmbeddingFailed|SidecarFailed $exception) {
            $this->line("{$label}: skipped ({$exception->getMessage()})");

            return;
        }

        $this->line(sprintf(
            '%-52s%8.2f%13.2f%7.2f%16s%10d',
            $label,
            $report->recall[5],
            $report->precision[5],
            $report->mrr,
            "{$report->emptyCorrect}/{$report->emptyTotal}",
            $report->queryMs,
        ));

        $this->store($report, $settings, $label, $reranker, $rewrite);
    }

    private function compareCandidate(RetrieverFactory $factory, RetrievalEvaluator $evaluator, CandidateIndex $candidateIndex, AiHttpClient $client, array $questions, RetrievalSettings $settings, string $reranker): int
    {
        $table = (string) $this->option('reindex-to');

        CandidateIndex::assertBenchTable($table);

        $model = (string) ($this->option('embed-model') ?: config('ai.embed_model'));

        $dimensions = (int) ($this->option('embed-dimensions') ?: $candidateIndex->dimensionsOf($model));

        $candidate = new Embedder($client, $model, $dimensions);

        $this->info("Re-embedding into {$table} with {$model} ({$dimensions} dims)...");

        $started = microtime(true);

        $count = $candidateIndex->build($table, $candidate, $dimensions, fn () => null);

        $seconds = (int) round(microtime(true) - $started);

        $benchSettings = $settings->with(table: $table, dimensions: $dimensions);

        $stageOne = $evaluator->evaluate($factory->make($benchSettings, RewriteMode::Off, 'none', embedder: $candidate), $questions);

        $reranked = $this->rerankedRecall($factory, $evaluator, $questions, $benchSettings, $reranker, $candidate);

        $this->line(sprintf('%-22s%6s%22s%22s  %-20s%s', 'model', 'dims', 'recall@5 (stage 1)', 'recall@5 (reranked)', "embed {$count} chunks", 'query latency'));

        $this->line(sprintf('%-22s%6d%22.2f%22s  %-20s%d ms', $model, $dimensions, $stageOne->recall[5], $reranked, "{$seconds} s", $stageOne->queryMs));

        if (! $this->option('keep-index')) {
            $candidateIndex->drop($table);
        }

        return Command::SUCCESS;
    }

    private function rerankedRecall(RetrieverFactory $factory, RetrievalEvaluator $evaluator, array $questions, RetrievalSettings $settings, string $reranker, Embedder $candidate): string
    {
        if ($reranker === 'none') {
            return 'n/a (no reranker)';
        }

        try {
            $report = $evaluator->evaluate($factory->make($settings, RewriteMode::Off, $reranker, $this->candidates(), $candidate), $questions);
        } catch (SidecarFailed $exception) {
            return 'n/a (sidecar down)';
        }

        return sprintf('%.2f', $report->recall[5]);
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

    private function rewriteMode(): RewriteMode
    {
        return RewriteMode::from((string) ($this->option('rewrite') ?: config('ai.retrieval.rewrite')));
    }

    private function rerankerDriver(): string
    {
        return (string) ($this->option('rerank') ?: config('ai.retrieval.reranker'));
    }

    private function candidates(): int
    {
        return (int) ($this->option('candidates') ?: config('ai.retrieval.rerank_candidates'));
    }

    private function strategy(): string
    {
        return HelpChunk::query()->where('kind', 'child')->exists()
            ? 'parent-child'
            : 'heading';
    }

    private function printReport(EvalReport $report, RetrievalSettings $settings, string $model, RewriteMode $rewrite, string $reranker): void
    {
        $version = (string) HelpChunk::query()->orderByDesc('updated_at')->value('help_version');

        $this->line(sprintf(
            'config: %s · threshold %.2f · k %d · rrf %d · %s · rerank %s · rewrite %s · chunks by %s · help %s',
            $settings->mode->value,
            $settings->similarityFloor,
            $settings->k,
            $settings->rrfConstant,
            $model,
            $reranker,
            $rewrite->value,
            $this->strategy(),
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

        $this->line(sprintf('query latency: %d ms per question', $report->queryMs));

        $this->reportMisses($report);

        $this->compareWithPrevious($report);

        $this->store($report, $settings, 'single run', $reranker, $rewrite);
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

    private function store(EvalReport $report, RetrievalSettings $settings, string $step, string $reranker, RewriteMode $rewrite): void
    {
        $stamp = now()->format('Y-m-d\THisv');

        $version = (string) HelpChunk::query()->orderByDesc('updated_at')->value('help_version');

        File::ensureDirectoryExists(storage_path('golden'));

        File::put(storage_path("golden/results-{$stamp}.json"), json_encode([
            'date' => $stamp,
            'mode' => $settings->mode->value,
            'floor' => $settings->similarityFloor,
            'k' => $settings->k,
            'help_version' => $version,
            'step' => $step,
            'reranker' => $reranker,
            'rewrite' => $rewrite->value,
            'chunks' => $this->strategy(),
            ...$report->toArray(),
        ], JSON_PRETTY_PRINT)."\n");
    }
}
