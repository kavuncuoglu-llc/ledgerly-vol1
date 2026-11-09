<?php

namespace App\Console\Commands;

use App\Ai\Bench\BenchFixture;
use App\Ai\Bench\BenchQuestions;
use App\Ai\Bench\EncodingBenchmark;
use App\Ai\Bench\EncodingResult;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnusableBenchmark;
use App\Ai\Shapes\InvoiceSummaryShape;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BenchEncoding extends Command
{
    private const BASELINE = 'json-compact';

    protected $signature = 'ai:bench-encoding
        {--model= : Model to ask the questions (default config ai.model)}
        {--rows=50 : How many fixture invoices to send}
        {--questions= : A JSON list of {question, expected}; default is generated from the rows sent}
        {--encodings= : Comma-separated subset of json-pretty,json-compact,yaml,markdown-table,tsv,toon}
        {--tokens-only : Count tokens only; ask the model nothing}
        {--fixture=storage/benchmarks/invoices.json : The invoice fixture}
        {--output=storage/benchmarks : Directory the result file is written to}';

    protected $description = 'Measure token cost and question-answering accuracy of each data encoding, and save the result by model and date';

    public function handle(EncodingBenchmark $benchmark): int
    {
        $date = Carbon::now()->toDateString();

        $model = (string) ($this->option('model') ?: config('ai.model'));

        try {
            $fixture = BenchFixture::fromFile($this->absolutePath((string) $this->option('fixture')));

            $rows = $fixture->first((int) $this->option('rows'));

            $questions = $this->questions($fixture, $rows);

            $encoded = $fixture->within(fn (): array => $benchmark->encode(
                rows: $rows,
                shape: new InvoiceSummaryShape(limit: $rows->count()),
                encodings: $this->encodings(),
            ));

            $results = $benchmark->run(
                encoded: $encoded,
                questions: $questions,
                askModel: ! $this->option('tokens-only'),
                model: $model,
            );
        } catch (UnusableBenchmark|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->printTable($results);

        $this->printMisses($results);

        $path = $this->write($date, $model, $rows->count(), $results);

        $this->line("wrote {$path}");

        return Command::SUCCESS;
    }

    private function questions(BenchFixture $fixture, Collection $rows): array
    {
        $path = (string) $this->option('questions');

        if ($path === '') {
            return $fixture->within(fn (): array => BenchQuestions::for($rows));
        }

        $decoded = json_decode((string) @file_get_contents($this->absolutePath($path)), true);

        if (! is_array($decoded)) {
            throw UnusableBenchmark::questionsMalformed($path);
        }

        return $decoded;
    }

    private function encodings(): array
    {
        $chosen = (string) $this->option('encodings');

        if ($chosen === '') {
            return array_keys(EncodingBenchmark::ENCODINGS);
        }

        return array_map('trim', explode(',', $chosen));
    }

    private function printTable(array $results): void
    {
        $baseline = $this->baselineTokens($results);

        $this->table(
            ['encoding', 'tokens', 'vs json', 'comprehension', 'tokens/correct'],
            array_map(fn (EncodingResult $result): array => [
                $result->name,
                number_format($result->tokens),
                $this->versusBaseline($result, $baseline),
                $this->comprehension($result),
                $this->tokensPerCorrect($result),
            ], $results),
        );
    }

    private function baselineTokens(array $results): int
    {
        foreach ($results as $result) {
            if ($result->name === self::BASELINE) {
                return $result->tokens;
            }
        }

        return 0;
    }

    private function versusBaseline(EncodingResult $result, int $baseline): string
    {
        if ($baseline === 0) {
            return 'n/a';
        }

        if ($result->name === self::BASELINE) {
            return 'baseline';
        }

        return sprintf('%+d%%', round(($result->tokens - $baseline) / $baseline * 100));
    }

    private function comprehension(EncodingResult $result): string
    {
        if ($result->asked === 0) {
            return 'not asked';
        }

        return "{$result->correct} / {$result->asked}";
    }

    private function tokensPerCorrect(EncodingResult $result): string
    {
        if ($result->asked === 0) {
            return 'n/a';
        }

        if ($result->correct === 0) {
            return 'none correct';
        }

        return (string) $result->tokensPerCorrect();
    }

    private function printMisses(array $results): void
    {
        foreach ($results as $result) {
            if ($result->missedQuestions === []) {
                continue;
            }

            $this->line("{$result->name} missed question(s): ".implode(', ', $result->missedQuestions));
        }
    }

    private function write(string $date, string $model, int $rows, array $results): string
    {
        $directory = $this->absolutePath((string) $this->option('output'));

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $slug = trim((string) preg_replace('/[^A-Za-z0-9.]+/', '-', $model), '-');

        $path = "{$directory}/{$date}-{$slug}.json";

        file_put_contents($path, json_encode([
            'date' => $date,
            'model' => $model,
            'rows' => $rows,
            'token_counter' => config('ai.token_counter'),
            'results' => array_map(fn (EncodingResult $result): array => $result->toArray(), $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
