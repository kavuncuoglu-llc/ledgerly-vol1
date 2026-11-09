<?php

namespace App\Ai\Profile;

use Symfony\Component\Process\Process;

final class ProcessBackgroundSampler implements BackgroundSampler
{
    private const FIRST_SAMPLE_WAIT_SECONDS = 10;

    private const POLL_MICROSECONDS = 50_000;

    private ?Process $process = null;

    public function start(string $provider, float $interval): void
    {
        $this->process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'ai:profile-sample',
            "--provider={$provider}",
            "--interval={$interval}",
        ]);

        $this->process->setTimeout(null);

        $this->process->start();

        $this->waitForFirstSample();
    }

    public function stop(): array
    {
        if ($this->process === null) {
            return [];
        }

        $this->process->stop(1);

        $output = $this->process->getOutput();

        $this->process = null;

        return $this->parse($output);
    }

    private function waitForFirstSample(): void
    {
        $deadline = microtime(true) + self::FIRST_SAMPLE_WAIT_SECONDS;

        while ($this->process->isRunning() && microtime(true) < $deadline) {
            if (str_contains($this->process->getOutput(), "\n")) {
                return;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function parse(string $output): array
    {
        $samples = [];

        foreach (array_filter(explode("\n", $output)) as $line) {
            $data = json_decode($line, true);

            if (is_array($data) && isset($data['at'])) {
                $samples[] = MemorySample::fromArray($data);
            }
        }

        return $samples;
    }
}
