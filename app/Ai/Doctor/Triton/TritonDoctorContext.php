<?php

namespace App\Ai\Doctor\Triton;

use App\Ai\Native\ServerReply;
use App\Ai\Triton\TritonMetrics;
use App\Ai\Triton\TritonServerClient;

final class TritonDoctorContext
{
    private const ROUND_TRIP_PROMPT = 'Reply with the single word: ok';

    private ?ServerReply $models = null;

    private ?ServerReply $metrics = null;

    private ?ServerReply $roundTrip = null;

    public function __construct(
        public readonly TritonServerClient $client,
        public readonly TritonServerClient $kserve,
        public readonly string $providerName,
        public readonly array $providerConfig,
        public readonly int $window,
        public readonly int $probeTimeoutSeconds,
    ) {}

    public function model(): string
    {
        return (string) ($this->providerConfig['model'] ?? '');
    }

    public function declaredModels(): array
    {
        return array_values((array) config('ai.triton.models'));
    }

    public function modelsReply(): ServerReply
    {
        return $this->models ??= $this->client->models();
    }

    public function servedModelIds(): array
    {
        $cards = (array) ($this->modelsReply()->body['data'] ?? []);

        return array_values(array_map(
            fn (mixed $card): string => (string) (is_array($card) ? ($card['id'] ?? '') : ''),
            $cards,
        ));
    }

    public function metricsReply(): ServerReply
    {
        return $this->metrics ??= $this->client->metrics();
    }

    public function metrics(): TritonMetrics
    {
        return TritonMetrics::parse($this->metricsReply()->text);
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
