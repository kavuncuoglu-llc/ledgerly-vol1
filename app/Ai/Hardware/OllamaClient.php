<?php

namespace App\Ai\Hardware;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OllamaClient
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(private readonly string $baseUrl) {}

    public static function forProvider(array $providerConfig): self
    {
        $baseUrl = (string) ($providerConfig['base_url'] ?? '');

        return new self(rtrim((string) preg_replace('#/v1/?$#', '', $baseUrl), '/'));
    }

    public function show(string $model): array
    {
        $body = $this->send('post', '/api/show', ['model' => $model]);

        return is_array($body['model_info'] ?? null)
            ? $body
            : [];
    }

    public function installedBytes(string $model): int
    {
        foreach ($this->send('get', '/api/tags')['models'] ?? [] as $installed) {
            if (($installed['name'] ?? '') === $model) {
                return (int) ($installed['size'] ?? 0);
            }
        }

        return 0;
    }

    public function running(): array
    {
        return $this->send('get', '/api/ps')['models'] ?? [];
    }

    private function send(string $method, string $path, array $body = []): array
    {
        if ($this->baseUrl === '') {
            return [];
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->{$method}($this->baseUrl.$path, $body);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return (array) $response->json();
    }
}
