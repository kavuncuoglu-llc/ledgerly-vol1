<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Exceptions\InvalidStatsWindow;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Exceptions\UnusableLoadTest;
use App\Ai\Load\BenchResult;
use App\Ai\Load\BenchServeCommand;
use App\Ai\Load\KneeEstimate;
use App\Ai\Load\KneeEstimator;
use App\Ai\Load\LoadReport;
use App\Ai\Load\LoadRunner;
use App\Ai\Load\ShapeSummary;
use App\Ai\Load\TrafficShapeExporter;
use App\Ai\Load\TrafficShapeFile;
use App\Ai\Native\VllmEndpoint;
use App\Ai\Stats\StatsWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AiLoad extends Command
{
    protected $signature = 'ai:load
        {--provider=vllm : The vLLM provider to load}
        {--requests= : Traffic-shape file, one JSON object per line (default: ai.load.shape_file)}
        {--export-shape : Write the traffic-shape file from model_calls first}
        {--feature=support.reply : The model_calls feature the shape is drawn from}
        {--since=7d : How far back --export-shape looks, such as 90m, 24h or 7d}
        {--limit=500 : Most recent calls --export-shape keeps}
        {--concurrency= : Simultaneous streams to test, comma-separated (default: ai.load.concurrency)}
        {--prompts-per-level=0 : Requests sent at each level (default: 8 per stream, at least 32)}
        {--ttft-p99-ms= : The time-to-first-token p99 the knee is judged against (default: ai.load.ttft_p99_ms)}
        {--model= : Hugging Face repository or local path vllm bench loads the tokenizer from (default: VLLM_MODEL)}
        {--vllm-bin= : The vllm command (default: ai.load.vllm_binary)}
        {--timeout= : Seconds each level may take (default: ai.load.timeout)}
        {--dry-run : Print the vllm bench serve commands without running them}';

    protected $description = 'Load-test a vLLM provider with vllm bench serve on the app\'s own traffic shape, print TTFT and inter-token latency by concurrency, and estimate the knee';

    public function handle(AiManager $ai, TrafficShapeExporter $exporter, LoadRunner $runner, KneeEstimator $estimator): int
    {
        try {
            $provider = $this->vllmProvider($ai);

            $shapes = $this->shapes($exporter);

            $levels = $this->levels();

            $this->line(ShapeSummary::of($shapes)->line());

            $command = $this->benchCommand($provider, ShapeSummary::of($shapes));

            if ($this->option('dry-run')) {
                return $this->printCommands($runner, $command, $levels);
            }

            return $this->measure($runner, $estimator, $command, $levels, $shapes);
        } catch (UnusableLoadTest|UnknownProvider|InvalidStatsWindow $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function vllmProvider(AiManager $ai): array
    {
        $name = (string) $this->option('provider');

        $config = $ai->providerConfig($name);

        if (! VllmEndpoint::isVllm($config)) {
            throw UnusableLoadTest::notVllm($name);
        }

        return $config;
    }

    private function shapes(TrafficShapeExporter $exporter): array
    {
        $path = $this->shapePath();

        if ($this->option('export-shape')) {
            $this->exportShapes($exporter, $path);
        }

        return TrafficShapeFile::read($path);
    }

    private function exportShapes(TrafficShapeExporter $exporter, string $path): void
    {
        $feature = (string) $this->option('feature');

        $since = (string) $this->option('since');

        $shapes = $exporter->export($feature, StatsWindow::since($since), (int) $this->option('limit'));

        if ($shapes === []) {
            throw UnusableLoadTest::noTraffic($feature, $since);
        }

        TrafficShapeFile::write($path, $shapes);

        $this->line(sprintf('wrote %d request shapes from model_calls (%s, last %s) to %s', count($shapes), $feature, $since, $path));
    }

    private function shapePath(): string
    {
        $path = (string) ($this->option('requests') ?: config('ai.load.shape_file'));

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function levels(): array
    {
        $given = (string) ($this->option('concurrency') ?: config('ai.load.concurrency'));

        $levels = [];

        foreach (explode(',', $given) as $level) {
            if (! ctype_digit(trim($level))) {
                throw UnusableLoadTest::badConcurrency($given);
            }

            if ((int) $level < 1) {
                throw UnusableLoadTest::badConcurrency($given);
            }

            $levels[] = (int) $level;
        }

        return array_values(array_unique($levels));
    }

    private function benchCommand(array $provider, ShapeSummary $shape): BenchServeCommand
    {
        $tokenizer = (string) ($this->option('model') ?: config('ai.vllm.repository'));

        if ($tokenizer === '') {
            throw UnusableLoadTest::tokenizerUnknown();
        }

        return new BenchServeCommand(
            binary: (string) ($this->option('vllm-bin') ?: config('ai.load.vllm_binary')),
            baseUrl: VllmEndpoint::nativeBaseUrl((string) $provider['base_url']),
            servedModel: (string) $provider['model'],
            tokenizerSource: $tokenizer,
            shape: $shape,
            apiKey: (string) ($provider['api_key'] ?? ''),
        );
    }

    private function printCommands(LoadRunner $runner, BenchServeCommand $command, array $levels): int
    {
        foreach ($levels as $level) {
            $arguments = $runner->commandFor($command, $level, (int) $this->option('prompts-per-level'), $this->resultsDirectory());

            $this->line(implode(' ', array_map('escapeshellarg', $arguments)));
        }

        $this->line('dry run: nothing was sent. vllm bench serve reads the key from OPENAI_API_KEY; ai:load sets it from the provider.');

        return Command::SUCCESS;
    }

    private function measure(LoadRunner $runner, KneeEstimator $estimator, BenchServeCommand $command, array $levels, array $shapes): int
    {
        $results = [];

        $directory = $this->resultsDirectory();

        try {
            foreach ($levels as $level) {
                $this->line("concurrency {$level}: running vllm bench serve");

                $results[] = $runner->runLevel(
                    command: $command,
                    concurrency: $level,
                    promptsOverride: (int) $this->option('prompts-per-level'),
                    directory: $directory,
                    environment: ['OPENAI_API_KEY' => $command->apiKey],
                    timeoutSeconds: (int) ($this->option('timeout') ?: config('ai.load.timeout')),
                );
            }
        } finally {
            $this->printResults($results);
        }

        $knee = $estimator->estimate($results, (float) ($this->option('ttft-p99-ms') ?: config('ai.load.ttft_p99_ms')));

        $this->line($knee->line());

        $this->save($results, $knee, ShapeSummary::of($shapes));

        return Command::SUCCESS;
    }

    private function printResults(array $results): void
    {
        if ($results === []) {
            return;
        }

        $this->table(LoadReport::headers(), LoadReport::rows($results));
    }

    private function save(array $results, KneeEstimate $knee, ShapeSummary $shape): void
    {
        $directory = (string) config('ai.load.directory');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/load-'.Carbon::now()->format('Y-m-d\THis').'.json';

        file_put_contents($path, json_encode([
            'provider' => (string) $this->option('provider'),
            'shape' => (array) $shape,
            'levels' => array_map(fn (BenchResult $result): array => $result->toArray(), $results),
            'knee' => [
                'concurrency' => $knee->concurrency,
                'basis' => $knee->basis,
                'ceiling_ms' => $knee->ceilingMs,
            ],
        ], JSON_PRETTY_PRINT));

        $this->line("saved {$path}");
    }

    private function resultsDirectory(): string
    {
        return rtrim((string) config('ai.load.directory'), '/').'/vllm-bench';
    }
}
