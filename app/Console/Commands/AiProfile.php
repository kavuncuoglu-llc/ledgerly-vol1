<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Exceptions\UnusableProfile;
use App\Ai\Hardware\ByteSize;
use App\Ai\Hardware\HardwareProbe;
use App\Ai\Hardware\OllamaClient;
use App\Ai\Profile\BackgroundSampler;
use App\Ai\Profile\MemoryProbeFactory;
use App\Ai\Profile\ProfileReport;
use App\Ai\Profile\ProfileRunner;
use App\Ai\Profile\PromptSet;
use Illuminate\Console\Command;

class AiProfile extends Command
{
    private const NOT_AVAILABLE = '---';

    protected $signature = 'ai:profile
        {--provider= : Provider to profile (default: ai.default)}
        {--prompts= : JSON file of prompts (default: ai.hardware.profile.prompts)}
        {--interval= : Seconds between memory samples (default: ai.hardware.profile.interval)}
        {--max-tokens= : Cap on tokens generated per prompt (default: ai.hardware.profile.max_tokens)}
        {--bandwidth-gbps= : Memory bandwidth for the decode ceiling, when it cannot be looked up}';

    protected $description = 'Run a fixed prompt set against a provider while sampling memory every interval, and print the timeline';

    public function handle(AiManager $ai, ProfileRunner $runner, BackgroundSampler $sampler, MemoryProbeFactory $probes, HardwareProbe $hardware): int
    {
        try {
            $provider = (string) ($this->option('provider') ?: config('ai.default'));

            $config = $this->configFor($ai, $provider);

            $prompts = PromptSet::fromFile($this->promptPath());
        } catch (UnusableProfile|UnknownProvider $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $interval = (float) ($this->option('interval') ?: config('ai.hardware.profile.interval'));

        $probe = $probes->make($provider);

        $baseline = $probe->read();

        $startedAt = microtime(true);

        $sampler->start($provider, $interval);

        try {
            $turns = $runner->run($provider, $prompts, $this->maxTokens(), coldStart: ! $baseline->modelLoaded);
        } finally {
            $samples = $sampler->stop();
        }

        $report = new ProfileReport($turns, $samples, $startedAt);

        $weights = OllamaClient::forProvider($config)->installedBytes((string) $config['model']);

        $this->printReport($report, $hardware->detect()->bandwidthGbps, $weights, $probe->residentSource());

        return $this->failedTurn($turns);
    }

    private function configFor(AiManager $ai, string $provider): array
    {
        $config = $ai->providerConfig($provider);

        if (! $ai->isConfigured($provider)) {
            throw UnusableProfile::notChatProvider($provider);
        }

        if (blank($config['base_url'] ?? null)) {
            throw UnusableProfile::notChatProvider($provider);
        }

        return $config;
    }

    private function promptPath(): string
    {
        $path = (string) ($this->option('prompts') ?: config('ai.hardware.profile.prompts'));

        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    private function maxTokens(): int
    {
        return (int) ($this->option('max-tokens') ?: config('ai.hardware.profile.max_tokens'));
    }

    private function failedTurn(array $turns): int
    {
        foreach ($turns as $turn) {
            if ($turn->error !== '') {
                $this->error("prompt {$turn->index}: {$turn->error}");

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function printReport(ProfileReport $report, float $detectedBandwidth, int $installedWeights, string $residentSource): void
    {
        $this->table(
            ['t(s)', 'resident GiB', 'gpu GiB', 'phase', 'prefill tok/s', 'decode tok/s', 'ctx tokens'],
            array_map(fn (array $row): array => $this->tableRow($row), $report->rows()),
        );

        $this->line("resident = {$residentSource}");

        $summary = $report->summary(
            weightsBytes: $installedWeights,
            bandwidthGbps: (float) ($this->option('bandwidth-gbps') ?: $detectedBandwidth),
        );

        $this->line($this->summaryLine($summary, $installedWeights, (float) ($this->option('bandwidth-gbps') ?: $detectedBandwidth)));
    }

    private function tableRow(array $row): array
    {
        return [
            number_format($row['second'], 1),
            number_format(ByteSize::gib($row['resident_bytes']), 1),
            number_format(ByteSize::gib($row['gpu_bytes']), 1),
            $row['phase'],
            $this->rate($row['prefill_tokens_per_second']),
            $this->rate($row['decode_tokens_per_second']),
            $row['context_tokens'] > 0
                ? number_format($row['context_tokens'])
                : self::NOT_AVAILABLE,
        ];
    }

    private function rate(float $tokensPerSecond): string
    {
        if ($tokensPerSecond <= 0.0) {
            return self::NOT_AVAILABLE;
        }

        return number_format($tokensPerSecond, 1);
    }

    private function summaryLine(array $summary, int $weights, float $bandwidth): string
    {
        $parts = [
            'summary: load-to-first-token '.($summary['load_to_first_token'] > 0
                ? number_format($summary['load_to_first_token'], 1).' s'
                : 'n/a (model was already loaded)'),
            'prefill '.$this->rate($summary['prefill_tokens_per_second']).' tok/s',
            'decode '.$this->rate($summary['decode_tokens_per_second']).' tok/s'.$this->ceilingNote($summary, $weights, $bandwidth),
            'peak resident '.ByteSize::format($summary['peak_resident_bytes']),
            'peak gpu '.ByteSize::format($summary['peak_gpu_bytes']),
            ByteSize::format($summary['spilled_bytes']).' spilled',
        ];

        return implode(' · ', $parts);
    }

    private function ceilingNote(array $summary, int $weights, float $bandwidth): string
    {
        if ($summary['decode_ceiling_tokens_per_second'] <= 0) {
            return ' (no bandwidth known: set AI_MEMORY_BANDWIDTH_GBPS for a decode ceiling)';
        }

        return sprintf(
            ' (%s GB/s over %s of weights: ceiling %s tok/s, measured %d%% of it)',
            number_format($bandwidth),
            ByteSize::format($weights),
            number_format($summary['decode_ceiling_tokens_per_second'], 1),
            (int) round($summary['decode_share_of_ceiling'] * 100),
        );
    }
}
