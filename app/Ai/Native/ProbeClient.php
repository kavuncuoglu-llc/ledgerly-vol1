<?php

namespace App\Ai\Native;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class ProbeClient
{
    protected const TIMEOUT_SECONDS = 5;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey = '',
        protected readonly array $headers = [],
    ) {}

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function host(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
    }

    protected function send(string $method, string $path, array $body = [], bool $authenticated = true, int $timeoutSeconds = self::TIMEOUT_SECONDS): ServerReply
    {
        if ($this->baseUrl === '') {
            return ServerReply::unreachable('no base_url is configured for this provider', 0);
        }

        $started = hrtime(true);

        try {
            $response = $this->pending($authenticated, $timeoutSeconds)->send($method, $this->baseUrl.$path, $this->payload($method, $body));
        } catch (ConnectionException $exception) {
            return ServerReply::unreachable($exception->getMessage(), $this->elapsed($started));
        }

        return new ServerReply(
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

        if ($this->headers !== []) {
            return $pending->withHeaders($this->headers);
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
