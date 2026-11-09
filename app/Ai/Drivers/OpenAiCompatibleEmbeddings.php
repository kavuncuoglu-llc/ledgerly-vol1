<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Drivers\Errors\OpenAiErrors;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Exceptions\EmbeddingFailed;

final class OpenAiCompatibleEmbeddings implements EmbeddingModel
{
    private readonly ProviderClient $client;

    public function __construct(
        private readonly array $config,
        ?ProviderClient $client = null,
    ) {
        $this->client = $client ?? new ProviderClient(
            config: $config,
            errors: new OpenAiErrors,
            headers: ['Authorization' => 'Bearer '.($config['api_key'] ?? '')],
        );
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function dimensions(): int
    {
        return (int) $this->config['dimensions'];
    }

    public function embed(array $texts, EmbedKind $kind): array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        $inputs = array_map(
            fn (string $text): string => $this->prefixFor($kind).$text,
            $texts,
        );

        $response = $this->client->post('/embeddings', [
            'model' => $this->model(),
            'input' => count($inputs) === 1
                ? $inputs[0]
                : $inputs,
        ]);

        return $this->vectorsOf($response->json('data'), count($texts));
    }

    private function prefixFor(EmbedKind $kind): string
    {
        return (string) ($this->config['prefixes'][$kind->value] ?? '');
    }

    private function vectorsOf(mixed $rows, int $expected): array
    {
        if (! is_array($rows)) {
            throw EmbeddingFailed::malformed();
        }

        if (count($rows) !== $expected) {
            throw EmbeddingFailed::malformed();
        }

        usort($rows, fn (array $left, array $right): int => ($left['index'] ?? 0) <=> ($right['index'] ?? 0));

        return array_map(fn (array $row): array => $this->checked($row['embedding'] ?? null), $rows);
    }

    private function checked(mixed $vector): array
    {
        if (! is_array($vector)) {
            throw EmbeddingFailed::malformed();
        }

        if ($this->dimensions() !== 0 && count($vector) !== $this->dimensions()) {
            throw EmbeddingFailed::wrongDimensions($this->model(), $this->dimensions(), count($vector));
        }

        return array_map('floatval', $vector);
    }
}
