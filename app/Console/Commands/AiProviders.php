<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsNativeStructuredOutput;
use App\Ai\Contracts\Capability;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\ModelCapabilities;
use App\Ai\Native\ServedModels;
use Illuminate\Console\Command;

class AiProviders extends Command
{
    private const EMPTY = '---';

    protected $signature = 'ai:providers {--check : Ask each configured chat provider which models it serves}';

    protected $description = 'List the configured providers with their driver, model and capabilities';

    public function handle(AiManager $ai, ServedModels $served): int
    {
        $rows = array_map(
            fn (string $name): array => $this->rowFor($ai, $served, $name),
            $ai->providerNames(),
        );

        $headers = ['name', 'driver', 'model', 'base_url', 'capabilities', 'license'];

        if ($this->option('check')) {
            $headers[] = 'check';
        }

        $this->table($headers, $rows);

        $this->line("default provider: {$this->defaultProvider()}");

        return Command::SUCCESS;
    }

    private function defaultProvider(): string
    {
        return (string) config('ai.default');
    }

    private function rowFor(AiManager $ai, ServedModels $served, string $name): array
    {
        $config = $ai->providerConfig($name);

        $driver = (string) $config['driver'];

        $row = match (true) {
            $driver === 'fake' => [$name, $driver, self::EMPTY, self::EMPTY, 'everything'],
            $driver === 'lexical' => [$name, $driver, self::EMPTY, self::EMPTY, 'rerank'],
            $driver === 'sidecar' => [$name, $driver, (string) ($config['model'] ?? self::EMPTY), (string) config('ai.sidecar.url'), 'rerank'],
            isset($config['dimensions']) => [$name, $driver, (string) $config['model'], (string) $config['base_url'], "embed ({$config['dimensions']})"],
            default => $this->chatRow($ai, $name, $driver, $config),
        };

        $row = [...$row, $this->licenseOf($config)];

        if (! $this->option('check')) {
            return $row;
        }

        return [...$row, $this->checkFor($ai, $served, $name, $driver, $config)];
    }

    private function licenseOf(array $config): string
    {
        $license = (string) ($config['license'] ?? '');

        if ($license === '') {
            return self::EMPTY;
        }

        $reviewed = (string) ($config['license_reviewed'] ?? '');

        if ($reviewed === '') {
            return "{$license} (unreviewed)";
        }

        return "{$license} (reviewed {$reviewed})";
    }

    private function chatRow(AiManager $ai, string $name, string $driver, array $config): array
    {
        $configured = $ai->isConfigured($name);

        return [
            $name,
            $driver,
            $configured ? (string) $config['model'] : '(not configured)',
            (string) ($config['base_url'] ?? self::EMPTY),
            implode(', ', $this->capabilitiesOf($ai, $name)),
        ];
    }

    private function capabilitiesOf(AiManager $ai, string $name): array
    {
        $model = $ai->chat($name);

        $labels = [];

        foreach (Capability::cases() as $capability) {
            if ($model->supports($capability)) {
                $labels[] = str_replace('_', '-', $capability->value);
            }
        }

        if (ModelCapabilities::has($model, SupportsContextWindowOverride::class)) {
            $labels[] = 'ctx-override';
        }

        if (ModelCapabilities::has($model, SupportsNativeStructuredOutput::class)) {
            $labels[] = 'native-structured';
        }

        return $labels;
    }

    private function checkFor(AiManager $ai, ServedModels $served, string $name, string $driver, array $config): string
    {
        if (in_array($driver, ['fake', 'lexical', 'sidecar', 'bedrock'], true)) {
            return self::EMPTY;
        }

        if (isset($config['dimensions'])) {
            return self::EMPTY;
        }

        if (! $ai->isConfigured($name)) {
            return self::EMPTY;
        }

        try {
            $ids = $served->idsFor($name);
        } catch (ModelRequestFailed $exception) {
            return 'unreachable: '.$exception->getMessage();
        }

        if (in_array($config['model'], $ids, true)) {
            return 'serves '.$config['model'];
        }

        return 'reachable, does not list '.$config['model'];
    }
}
