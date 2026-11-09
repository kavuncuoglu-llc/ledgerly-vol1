<?php

namespace App\Ai\Llama;

use App\Ai\Native\LlamaEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class LlamaServerClient
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
    ) {}

    public static function forProvider(array $providerConfig): self
    {
        return new self(
            baseUrl: LlamaEndpoint::nativeBaseUrl((string) ($providerConfig['base_url'] ?? '')),
            apiKey: (string) ($providerConfig['api_key'] ?? ''),
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function host(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
    }

    public function health(): LlamaReply
    {
        return $this->send('GET', '/health');
    }

    public function props(): LlamaReply
    {
        return $this->send('GET', '/props');
    }

    public function propsWithoutKey(): LlamaReply
    {
        return $this->send('GET', '/props', authenticated: false);
    }

    public function slots(): LlamaReply
    {
        return $this->send('GET', '/slots');
    }

    public function metrics(): LlamaReply
    {
        return $this->send('GET', '/metrics');
    }

    public function tokenize(string $text): LlamaReply
    {
        return $this->send('POST', '/tokenize', ['content' => $text]);
    }

    public function applyTemplate(array $messages, int $timeoutSeconds): LlamaReply
    {
        return $this->send('POST', '/apply-template', ['messages' => $messages], timeoutSeconds: $timeoutSeconds);
    }

    public function completion(array $body, int $timeoutSeconds): LlamaReply
    {
        return $this->send('POST', '/completion', $body, timeoutSeconds: $timeoutSeconds);
    }

    public function chatCompletion(array $body, int $timeoutSeconds): LlamaReply
    {
        return $this->send('POST', '/v1/chat/completions', $body, timeoutSeconds: $timeoutSeconds);
    }

    private function send(string $method, string $path, array $body = [], bool $authenticated = true, int $timeoutSeconds = self::TIMEOUT_SECONDS): LlamaReply
    {
        if ($this->baseUrl === '') {
            return LlamaReply::unreachable('no base_url is configured for this provider', 0);
        }

        $started = hrtime(true);

        try {
            $response = $this->pending($authenticated, $timeoutSeconds)->send($method, $this->baseUrl.$path, $this->payload($method, $body));
        } catch (ConnectionException $exception) {
            return LlamaReply::unreachable($exception->getMessage(), $this->elapsed($started));
        }

        return new LlamaReply(
            status: $response->status(),
            body: (array) $response->json(),
            text: $response->body(),
            milliseconds: $this->elapsed($started),
        );
    }

    private function pending(bool $authenticated, int $timeoutSeconds): PendingRequest
    {
        $pending = Http::acceptJson()->timeout($timeoutSeconds);

        if (! $authenticated) {
            return $pending;
        }

        if ($this->apiKey === '') {
            return $pending;
        }

        return $pending->withToken($this->apiKey);
    }

    private function payload(string $method, array $body): array
    {
        if ($method === 'GET') {
            return [];
        }

        return ['json' => $body];
    }

    private function elapsed(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
