<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\CheckStatus;
use App\Ai\Doctor\Doctor;
use App\Ai\Doctor\DoctorContextFactory;
use App\Ai\Doctor\Llama\LlamaDoctor;
use App\Ai\Doctor\Llama\LlamaDoctorContextFactory;
use App\Ai\Doctor\Vllm\VllmDoctor;
use App\Ai\Doctor\Vllm\VllmDoctorContextFactory;
use App\Ai\Exceptions\UnknownModelArchitecture;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Hardware\ByteSize;
use App\Ai\Hardware\HardwareProbe;
use App\Ai\Hardware\ModelSpecs;
use App\Ai\Hardware\OllamaClient;
use App\Ai\Hardware\SizingCalculator;
use App\Ai\Hardware\SizingResult;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiDoctor extends Command
{
    protected $signature = 'ai:doctor
        {--size : Check that a model, its KV cache and headroom fit the memory this machine can give a GPU}
        {--model= : Model name, e.g. llama3.1:70b-instruct-q4_K_M (default: the provider\'s model)}
        {--provider= : Provider whose Ollama, llama-server or vLLM is checked, or asked about the model with --size (default: ai.default)}
        {--ctx= : Context window in tokens the checks and the sizing compare against (default: ai.budget.window)}
        {--slots=1 : Parallel sequences the server keeps a cache for}
        {--cache=f16 : KV cache type: f16, q8_0 or q4_0}
        {--reserve-gib=0 : Memory other processes on this machine need, on top of the headroom}
        {--reachable-gib= : Memory the accelerator can reach, instead of detecting it}
        {--parameters-billions= : Parameters in billions, for a model that is not in the catalogue or on the server}
        {--layers= : Transformer layers}
        {--kv-heads= : Key/value heads}
        {--head-dim= : Head dimension}
        {--quantization= : Weight quantization, e.g. q4_k_m}';

    protected $description = 'Check an Ollama, llama-server or vLLM setup for the silent failures (context window, placement, slots, exposure), or with --size, memory sizing';

    public function handle(AiManager $ai, HardwareProbe $probe, ModelSpecs $specs, SizingCalculator $calculator, DoctorContextFactory $contexts, Doctor $doctor, LlamaDoctorContextFactory $llamaContexts, LlamaDoctor $llamaDoctor, VllmDoctorContextFactory $vllmContexts, VllmDoctor $vllmDoctor): int
    {
        if (! $this->option('size')) {
            return $this->runChecks($ai, $contexts, $doctor, $llamaContexts, $llamaDoctor, $vllmContexts, $vllmDoctor);
        }

        try {
            $result = $this->sized($ai, $probe, $specs, $calculator);
        } catch (UnknownModelArchitecture|UnknownProvider|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (! $result->hardware->isDetected()) {
            $this->error("Cannot tell how much memory the GPU can reach: {$result->hardware->reachableNote}.");

            return Command::FAILURE;
        }

        $this->printResult($result);

        return $result->fits()
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function runChecks(AiManager $ai, DoctorContextFactory $contexts, Doctor $doctor, LlamaDoctorContextFactory $llamaContexts, LlamaDoctor $llamaDoctor, VllmDoctorContextFactory $vllmContexts, VllmDoctor $vllmDoctor): int
    {
        $provider = (string) ($this->option('provider') ?: config('ai.default'));

        try {
            $ai->providerConfig($provider);
        } catch (UnknownProvider $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $window = (int) ($this->option('ctx') ?: config('ai.budget.window'));

        if ($llamaContexts->isLlamaServer($provider)) {
            return $this->runLlamaChecks($llamaContexts, $llamaDoctor, $provider, $window);
        }

        if ($vllmContexts->isVllm($provider)) {
            return $this->runVllmChecks($vllmContexts, $vllmDoctor, $provider, $window);
        }

        if (! $contexts->isOllama($provider)) {
            $this->error("Provider {$provider} does not point at an Ollama server, a llama-server or a vLLM server. Pass --provider=<an Ollama, llama-server or vLLM provider>, or set AI_OLLAMA=true, AI_LLAMA=true or AI_VLLM=true on the provider.");

            return Command::FAILURE;
        }

        $results = $doctor->run($contexts->make($provider, $window));

        foreach ($results as $result) {
            $this->line($result->line());
        }

        return $this->summarize($results);
    }

    private function runLlamaChecks(LlamaDoctorContextFactory $contexts, LlamaDoctor $doctor, string $provider, int $window): int
    {
        $context = $contexts->make($provider, $window);

        $this->line("llama-server at {$context->client->baseUrl()}");

        $results = $doctor->run($context);

        foreach ($results as $result) {
            $this->line($result->line());
        }

        return $this->summarize($results);
    }

    private function runVllmChecks(VllmDoctorContextFactory $contexts, VllmDoctor $doctor, string $provider, int $window): int
    {
        $context = $contexts->make($provider, $window);

        $this->line("vLLM at {$context->client->baseUrl()}");

        $results = $doctor->run($context);

        foreach ($results as $result) {
            $this->line($result->line());
        }

        return $this->summarize($results);
    }

    private function summarize(array $results): int
    {
        $warnings = $this->count($results, CheckStatus::Warn);

        $failures = $this->count($results, CheckStatus::Fail);

        $this->line("{$warnings} ".($warnings === 1 ? 'warning' : 'warnings').", {$failures} ".($failures === 1 ? 'failure' : 'failures'));

        return $failures === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function count(array $results, CheckStatus $status): int
    {
        return count(array_filter($results, fn (CheckResult $result): bool => $result->status === $status));
    }

    private function sized(AiManager $ai, HardwareProbe $probe, ModelSpecs $specs, SizingCalculator $calculator): SizingResult
    {
        $provider = (string) ($this->option('provider') ?: config('ai.default'));

        $config = $ai->providerConfig($provider);

        $model = (string) ($this->option('model') ?: ($config['model'] ?? ''));

        if ($this->option('reachable-gib') !== null) {
            config(['ai.hardware.reachable_gib' => (float) $this->option('reachable-gib')]);
        }

        $spec = $specs->resolve($model, $this->overrides(), OllamaClient::forProvider($config));

        return $calculator->estimate(
            spec: $spec,
            hardware: $probe->detect(),
            context: (int) ($this->option('ctx') ?: config('ai.budget.window')),
            slots: max(1, (int) $this->option('slots')),
            cacheType: (string) $this->option('cache'),
            reserveBytes: ByteSize::fromGib((float) $this->option('reserve-gib')),
        );
    }

    private function overrides(): array
    {
        return [
            'parameters_billions' => $this->numberOption('parameters-billions'),
            'layers' => $this->numberOption('layers'),
            'kv_heads' => $this->numberOption('kv-heads'),
            'head_dim' => $this->numberOption('head-dim'),
            'quantization' => $this->option('quantization') ?: null,
        ];
    }

    private function numberOption(string $name): float|int|null
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }

    private function printResult(SizingResult $result): void
    {
        $this->line($this->summaryLine($result));

        $this->line("context {$result->context} x {$result->slots} ".($result->slots === 1 ? 'slot' : 'slots').", cache {$result->cacheType}, model from {$result->spec->source}");

        $this->line($this->reachableLine($result));

        $this->line("binding constraint: {$result->bindingConstraint()}");

        $this->line($this->verdictLine($result));

        foreach ($result->alternatives as $alternative) {
            $this->line(sprintf('  %s %s: %s', $alternative->fits ? 'would fit:' : 'still no:', $alternative->change, ByteSize::format($alternative->totalBytes)));
        }
    }

    private function summaryLine(SizingResult $result): string
    {
        return sprintf(
            'weights %s (%s) · KV cache %s (%s; %s at q8_0) · headroom %s · total %s',
            ByteSize::format($result->weightsBytes),
            $result->spec->quantization,
            ByteSize::format($result->cacheBytes),
            $result->cacheType,
            ByteSize::format($result->cacheBytesAtQ8),
            ByteSize::format($result->headroomBytes),
            ByteSize::format($result->totalBytes),
        );
    }

    private function reachableLine(SizingResult $result): string
    {
        return sprintf(
            'reachable on this machine (%s): %s (%s)',
            $result->hardware->label,
            ByteSize::format($result->hardware->reachableBytes),
            $result->hardware->reachableNote,
        );
    }

    private function verdictLine(SizingResult $result): string
    {
        if ($result->fits()) {
            return '✓ fits with '.ByteSize::format($result->spareBytes()).' to spare';
        }

        return '✗ does not fit: short by '.ByteSize::format(abs($result->spareBytes()));
    }
}
