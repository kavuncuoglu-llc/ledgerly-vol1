<?php

namespace App\Ai\Hardware;

use App\Ai\Native\OllamaEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OllamaClient
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(private readonly string $baseUrl) {}

    public static function forProvider(array $providerConfig): self
    {
        return new self(OllamaEndpoint::nativeBaseUrl((string) ($providerConfig['base_url'] ?? '')));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function version(): string
    {
        return (string) ($this->send('get', '/api/version')['version'] ?? '');
    }

    public function show(string $model): array
    {
        $body = $this->send('post', '/api/show', ['model' => $model]);

        return is_array($body['model_info'] ?? null)
            ? $body
            : [];
    }

    public function installed(): array
    {
        return $this->send('get', '/api/tags')['models'] ?? [];
    }

    public function installedBytes(string $model): int
    {
        foreach ($this->installed() as $installed) {
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

    public function timedPost(string $path, array $body, int $timeoutSeconds): array
    {
        $started = hrtime(true);

        try {
            $response = Http::timeout($timeoutSeconds)->post($this->baseUrl.$path, $body);
        } catch (ConnectionException $exception) {
            return $this->outcome(false, 0, [], $started, $exception->getMessage());
        }

        return $this->outcome(
            $response->successful(),
            $response->status(),
            (array) $response->json(),
            $started,
            $response->successful()
                ? ''
                : "HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200),
        );
    }

    private function outcome(bool $ok, int $status, array $body, int|float $started, string $error): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'milliseconds' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error' => $error,
        ];
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
