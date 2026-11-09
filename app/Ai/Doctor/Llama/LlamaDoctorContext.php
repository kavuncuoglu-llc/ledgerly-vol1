<?php

namespace App\Ai\Doctor\Llama;

use App\Ai\Doctor\AiQueueWorkers;
use App\Ai\Llama\LlamaMetrics;
use App\Ai\Llama\LlamaServerClient;
use App\Ai\Native\ServerReply;

final class LlamaDoctorContext
{
    private const ROUND_TRIP_PROMPT = 'Reply with the single word: ok';

    private ?ServerReply $props = null;

    private ?ServerReply $metrics = null;

    private ?ServerReply $roundTrip = null;

    public function __construct(
        public readonly LlamaServerClient $client,
        public readonly string $providerName,
        public readonly array $providerConfig,
        public readonly int $window,
        public readonly AiQueueWorkers $workers,
        public readonly int $probeTimeoutSeconds,
    ) {}

    public function model(): string
    {
        return (string) ($this->providerConfig['model'] ?? '');
    }

    public function props(): ServerReply
    {
        return $this->props ??= $this->client->props();
    }

    public function totalSlots(): int
    {
        return (int) ($this->props()->body['total_slots'] ?? 0);
    }

    public function slotContext(): int
    {
        return (int) ($this->props()->body['default_generation_settings']['n_ctx'] ?? 0);
    }

    public function metricsReply(): ServerReply
    {
        return $this->metrics ??= $this->client->metrics();
    }

    public function metrics(): LlamaMetrics
    {
        return LlamaMetrics::parse($this->metricsReply()->text);
    }

    public function roundTrip(): ServerReply
    {
        return $this->roundTrip ??= $this->client->chatCompletion([
            'model' => $this->model(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => self::ROUND_TRIP_PROMPT,
                ],
            ],
            'max_tokens' => 8,
            'temperature' => 0,
            'stream' => false,
        ], $this->probeTimeoutSeconds);
    }
}
