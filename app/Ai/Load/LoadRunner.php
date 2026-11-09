<?php

namespace App\Ai\Load;

use App\Ai\Exceptions\UnusableLoadTest;

final class LoadRunner
{
    private const MINIMUM_PROMPTS = 32;

    private const PROMPTS_PER_STREAM = 8;

    public function __construct(private readonly BenchProcess $process) {}

    public function promptsFor(int $concurrency, int $override): int
    {
        if ($override > 0) {
            return $override;
        }

        return max(self::MINIMUM_PROMPTS, $concurrency * self::PROMPTS_PER_STREAM);
    }

    public function commandFor(BenchServeCommand $command, int $concurrency, int $promptsOverride, string $directory): array
    {
        return $command->forLevel(
            concurrency: $concurrency,
            prompts: $this->promptsFor($concurrency, $promptsOverride),
            resultDirectory: $directory,
            resultFile: $this->resultFile($concurrency),
        );
    }

    public function runLevel(BenchServeCommand $command, int $concurrency, int $promptsOverride, string $directory, array $environment, int $timeoutSeconds): BenchResult
    {
        if (! $this->process->isAvailable($command->binary)) {
            throw UnusableLoadTest::benchUnavailable($command->binary);
        }

        $outcome = $this->process->run(
            $this->commandFor($command, $concurrency, $promptsOverride, $directory),
            $environment,
            $timeoutSeconds,
        );

        if (! $outcome->succeeded()) {
            throw UnusableLoadTest::benchFailed($concurrency, $outcome->exitCode, $this->tail($outcome->output));
        }

        return BenchResult::fromJson($concurrency, $this->resultOf($concurrency, $directory));
    }

    private function resultOf(int $concurrency, string $directory): array
    {
        $path = $directory.'/'.$this->resultFile($concurrency);

        $result = is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        if (! is_array($result)) {
            throw UnusableLoadTest::resultMissing($concurrency, $path);
        }

        return $result;
    }

    private function resultFile(int $concurrency): string
    {
        return "level-{$concurrency}.json";
    }

    private function tail(string $output): string
    {
        $lines = array_slice(explode("\n", trim($output)), -5);

        return implode(' | ', $lines);
    }
}
