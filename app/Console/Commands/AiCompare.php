<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Compare\Cases\BuiltCases;
use App\Ai\Compare\Cases\CaseSource;
use App\Ai\Compare\ComparisonReport;
use App\Ai\Compare\ComparisonRunner;
use App\Ai\Compare\Cost;
use App\Ai\Contracts\ChatModel;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Exceptions\UnusableComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AiCompare extends Command
{
    private const NOT_APPLICABLE = '---';

    protected $signature = 'ai:compare
        {--providers= : Comma-separated provider names from config/ai.php, e.g. local,mid,frontier}
        {--feature= : Comma-separated features (default: every feature with a golden file)}
        {--golden= : Golden file for the one feature named by --feature}
        {--limit=0 : At most this many cases per feature, spread evenly through the file}
        {--judge= : Provider that grades free-text replies}
        {--replay : Use requests recorded with AI_RECORD_REQUESTS instead of a golden file}
        {--stream : Stream each call so time to first token is measured}
        {--output= : Directory the result file is written to (default: ai.compare.output)}';

    protected $description = 'Run the same golden requests against several providers and report quality, tokens, latency, cost and quality per dollar';

    public function handle(AiManager $ai, CaseSource $source, ComparisonRunner $runner): int
    {
        try {
            $providers = $this->providers($ai);

            $judge = $this->judge($ai);

            $built = $this->casesPerFeature($source, $judge);
        } catch (UnusableComparison|UnknownProvider|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->printSkipped($built);

        $report = $this->runAll($runner, $built, $providers, $judge);

        if ($report === null) {
            return Command::FAILURE;
        }

        $this->printReport($report);

        $this->line('wrote '.$this->write($report, $providers, $built));

        return Command::SUCCESS;
    }

    private function providers(AiManager $ai): array
    {
        $names = $this->listOption('providers');

        if ($names === []) {
            throw UnusableComparison::noProviders();
        }

        foreach ($names as $name) {
            $this->assertComparable($ai, $name);
        }

        return $names;
    }

    private function assertComparable(AiManager $ai, string $name): void
    {
        $config = $ai->providerConfig($name);

        $driver = (string) ($config['driver'] ?? '');

        if (in_array($driver, ['sidecar', 'lexical'], true)) {
            throw UnusableComparison::notAChatProvider($name, "the {$driver} driver is not a chat model.");
        }

        if (isset($config['dimensions'])) {
            throw UnusableComparison::notAChatProvider($name, 'it is an embedding provider.');
        }

        if (! $ai->isConfigured($name)) {
            throw UnusableComparison::notConfigured($name);
        }
    }

    private function judge(AiManager $ai): ?ChatModel
    {
        $name = (string) $this->option('judge');

        if ($name === '') {
            return null;
        }

        $this->assertComparable($ai, $name);

        return $ai->chat($name);
    }

    private function casesPerFeature(CaseSource $source, ?ChatModel $judge): array
    {
        $features = $this->listOption('feature') ?: $source->features();

        $golden = (string) $this->option('golden');

        if ($golden !== '' && count($features) !== 1) {
            throw UnusableComparison::goldenNeedsOneFeature();
        }

        $built = [];

        foreach ($features as $feature) {
            $built[$feature] = $source->build(
                feature: $feature,
                golden: $golden === ''
                    ? null
                    : $golden,
                limit: (int) $this->option('limit'),
                replay: (bool) $this->option('replay'),
                judge: $judge,
            );
        }

        return $built;
    }

    private function runAll(ComparisonRunner $runner, array $built, array $providers, ?ChatModel $judge): ?ComparisonReport
    {
        $outcomes = [];

        foreach ($built as $feature => $cases) {
            $this->line("{$feature}: ".count($cases->cases).' cases x '.count($providers).' providers');

            try {
                array_push($outcomes, ...$runner->run(
                    cases: $cases->cases,
                    providers: $providers,
                    stream: (bool) $this->option('stream'),
                    progress: fn () => $this->output->write('.'),
                ));
            } catch (ModelRequestFailed $exception) {
                $this->newLine();

                $this->error('The judge failed: '.$exception->getMessage());

                return null;
            }

            $this->newLine();
        }

        return new ComparisonReport($outcomes);
    }

    private function printSkipped(array $built): void
    {
        foreach ($built as $feature => $cases) {
            foreach ($cases->skipped as $reason) {
                $this->warn("{$feature}: skipped {$reason}");
            }
        }
    }

    private function printReport(ComparisonReport $report): void
    {
        foreach ($report->features() as $feature) {
            $this->newLine();

            $this->table(
                ['feature', 'provider', 'cases', 'errors', 'quality', 'in tok', 'out tok', 'p50 ms', 'ttft ms', '$/1K requests', 'quality per $'],
                array_map(fn (string $provider): array => $this->summaryRow($report->summary($feature, $provider)), $report->providers($feature)),
            );

            $this->printCategories($report, $feature);
        }

        $this->printFootnotes($report);
    }

    private function summaryRow(array $summary): array
    {
        return [
            $summary['feature'],
            $summary['provider'],
            $summary['cases'],
            $summary['errors'],
            number_format($summary['quality'], 2),
            number_format($summary['prompt_tokens']),
            number_format($summary['completion_tokens']),
            $this->milliseconds($summary['p50_ms']),
            $this->milliseconds($summary['ttft_ms']),
            $this->dollars($summary),
            $this->qualityPerDollar($summary),
        ];
    }

    private function milliseconds(?float $value): string
    {
        if ($value === null) {
            return self::NOT_APPLICABLE;
        }

        return number_format($value);
    }

    private function dollars(array $summary): string
    {
        if ($summary['cost_per_1k'] === null) {
            return 'no rate';
        }

        $formatted = '$'.number_format($summary['cost_per_1k'], 2);

        return match ($summary['cost_basis']) {
            Cost::BASIS_FREE => $formatted.'*',
            Cost::BASIS_GPU => $formatted.'+',
            default => $formatted,
        };
    }

    private function qualityPerDollar(array $summary): string
    {
        $value = $summary['quality_per_dollar'];

        if ($value === null) {
            return self::NOT_APPLICABLE;
        }

        if ($value === INF) {
            return 'inf';
        }

        return number_format($value, 2);
    }

    private function printCategories(ComparisonReport $report, string $feature): void
    {
        $categories = $report->categories($feature);

        if (count($categories) < 2) {
            return;
        }

        $this->line("by category (quality), {$feature}:");

        $this->table(
            ['provider', ...$categories],
            array_map(function (string $provider) use ($report, $feature, $categories): array {
                $byCategory = $report->qualityByCategory($feature, $provider);

                return [
                    $provider,
                    ...array_map(fn (string $category): string => isset($byCategory[$category])
                        ? number_format($byCategory[$category], 2)
                        : self::NOT_APPLICABLE, $categories),
                ];
            }, $report->providers($feature)),
        );
    }

    private function printFootnotes(ComparisonReport $report): void
    {
        $bases = array_unique(array_map(
            fn ($outcome): string => $outcome->cost?->basis ?? Cost::BASIS_UNKNOWN,
            $report->outcomes(),
        ));

        if (in_array(Cost::BASIS_FREE, $bases, true)) {
            $this->line('* runs on this machine: no GPU-hour cost is configured, so it counts as $0.00 (set AI_GPU_HOUR_COST to price it)');
        }

        if (in_array(Cost::BASIS_GPU, $bases, true)) {
            $this->line('+ GPU-hour cost x wall-clock time per request, one request at a time (no batching)');
        }

        if (in_array(Cost::BASIS_UNKNOWN, $bases, true)) {
            $this->line('no rate: a hosted provider with no price in ai.rates, so its cost is not counted');
        }
    }

    private function write(ComparisonReport $report, array $providers, array $built): string
    {
        $directory = $this->absolute((string) ($this->option('output') ?: config('ai.compare.output')));

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $this->pathFor($directory);

        file_put_contents($path, json_encode([
            'date' => Carbon::now()->toIso8601String(),
            'providers' => $providers,
            'judge' => $this->option('judge') ?: null,
            'streamed' => (bool) $this->option('stream'),
            'replay' => (bool) $this->option('replay'),
            'skipped' => array_map(fn (BuiltCases $cases): array => $cases->skipped, $built),
            ...$report->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    private function pathFor(string $directory): string
    {
        $path = $directory.'/compare-'.Carbon::now()->toDateString().'.json';

        if (! file_exists($path)) {
            return $path;
        }

        return $directory.'/compare-'.Carbon::now()->format('Y-m-d\THis').'.json';
    }

    private function listOption(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($name)))));
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
