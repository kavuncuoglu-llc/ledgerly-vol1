<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Sampling\ChatSampler;
use App\Ai\Sampling\SampleResult;
use Illuminate\Console\Command;

class AiSample extends Command
{
    private const NUMERIC_PARAMETERS = [
        'temperature' => 'float',
        'top-p' => 'float',
        'top-k' => 'int',
        'min-p' => 'float',
        'max-tokens' => 'int',
        'seed' => 'int',
        'frequency-penalty' => 'float',
        'presence-penalty' => 'float',
        'repeat-penalty' => 'float',
    ];

    protected $signature = 'ai:sample
        {prompt : The user prompt to send}
        {--profile=assistant : Sampling profile from config/ai.php}
        {--model= : Model name, defaults to AI_MODEL}
        {--runs=1 : How many times to send the prompt}
        {--temperature= : Temperature}
        {--top-p= : Nucleus sampling cutoff}
        {--top-k= : Top-k cutoff}
        {--min-p= : Min-p cutoff}
        {--max-tokens= : Cap on completion tokens}
        {--seed= : Sampler seed}
        {--frequency-penalty= : Frequency penalty}
        {--presence-penalty= : Presence penalty}
        {--repeat-penalty= : Repetition penalty}
        {--stop=* : Stop sequence, repeatable; \n is read as a newline}';

    protected $description = 'Send a prompt to the configured model with explicit sampling parameters';

    public function handle(ChatSampler $sampler): int
    {
        $profile = (string) $this->option('profile');

        if (! is_array(config("ai.profiles.{$profile}"))) {
            $this->error("Unknown profile [{$profile}].");

            return Command::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));

        try {
            $this->runSamples($sampler, $runs);
        } catch (ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function runSamples(ChatSampler $sampler, int $runs): void
    {
        $parameters = $this->parameters();

        foreach (range(1, $runs) as $run) {
            $result = $sampler->sample(
                prompt: (string) $this->argument('prompt'),
                parameters: $parameters,
                model: $this->option('model') ?: null,
            );

            $this->line($this->formatRun($run, $result));
        }
    }

    private function parameters(): array
    {
        return [
            ...config('ai.profiles.'.$this->option('profile')),
            ...$this->overrides(),
        ];
    }

    private function overrides(): array
    {
        $overrides = [];

        foreach (self::NUMERIC_PARAMETERS as $option => $type) {
            $value = $this->option($option);

            if ($value === null) {
                continue;
            }

            $overrides[str_replace('-', '_', $option)] = $type === 'int'
                ? (int) $value
                : (float) $value;
        }

        $stops = $this->stopSequences();

        if ($stops !== []) {
            $overrides['stop'] = $stops;
        }

        return $overrides;
    }

    private function stopSequences(): array
    {
        return array_map(
            fn (string $sequence): string => stripcslashes($sequence),
            $this->option('stop'),
        );
    }

    private function formatRun(int $run, SampleResult $result): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $result->content));

        return "[{$run}] {$text}  {$result->finishReason} · in {$result->promptTokens} out {$result->completionTokens}";
    }
}
