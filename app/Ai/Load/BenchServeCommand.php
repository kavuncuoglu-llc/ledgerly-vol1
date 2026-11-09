<?php

namespace App\Ai\Load;

final readonly class BenchServeCommand
{
    public function __construct(
        public string $binary,
        public string $baseUrl,
        public string $servedModel,
        public string $tokenizerSource,
        public ShapeSummary $shape,
        public string $apiKey = '',
    ) {}

    public function forLevel(int $concurrency, int $prompts, string $resultDirectory, string $resultFile): array
    {
        return [
            $this->binary,
            'bench',
            'serve',
            '--backend',
            'openai-chat',
            '--base-url',
            $this->baseUrl,
            '--endpoint',
            '/v1/chat/completions',
            '--model',
            $this->tokenizerSource,
            '--served-model-name',
            $this->servedModel,
            '--dataset-name',
            'random',
            '--random-input-len',
            (string) max(1, $this->shape->inputTokens - $this->shape->prefixTokens),
            '--random-output-len',
            (string) max(1, $this->shape->outputTokens),
            '--random-prefix-len',
            (string) $this->shape->prefixTokens,
            '--num-prompts',
            (string) $prompts,
            '--max-concurrency',
            (string) $concurrency,
            '--ignore-eos',
            '--percentile-metrics',
            'ttft,tpot,itl,e2el',
            '--metric-percentiles',
            '50,99',
            '--disable-tqdm',
            '--save-result',
            '--result-dir',
            $resultDirectory,
            '--result-filename',
            $resultFile,
        ];
    }
}
