<?php

namespace App\Ai\Doctor\Vllm;

use App\Ai\Doctor\AiQueueWorkers;
use App\Ai\Native\ServerReply;
use App\Ai\Vllm\VllmMetrics;
use App\Ai\Vllm\VllmServerClient;

final class VllmDoctorContext
{
    private const ROUND_TRIP_PROMPT = 'Reply with the single word: ok';

    private ?ServerReply $models = null;

    private ?ServerReply $metrics = null;

    private ?ServerReply $roundTrip = null;

    public function __construct(
        public readonly VllmServerClient $client,
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

    public function modelsReply(): ServerReply
    {
        return $this->models ??= $this->client->models();
    }

    public function servedModels(): array
    {
        $cards = $this->modelsReply()->body['data'] ?? [];

        return array_values(array_filter((array) $cards, 'is_array'));
    }

    public function servedModel(string $id): array
    {
        foreach ($this->servedModels() as $card) {
            if (($card['id'] ?? null) === $id) {
                return $card;
            }
        }

        return [];
    }

    public function maxModelLen(): int
    {
        $card = $this->servedModel($this->model());

        if ($card === []) {
            $card = $this->servedModels()[0] ?? [];
        }

        return (int) ($card['max_model_len'] ?? 0);
    }

    public function metricsReply(): ServerReply
    {
        return $this->metrics ??= $this->client->metrics();
    }

    public function metrics(): VllmMetrics
    {
        return VllmMetrics::parse($this->metricsReply()->text);
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
