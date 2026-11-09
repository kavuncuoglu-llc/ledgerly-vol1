<?php

namespace App\Ai\Drivers\Support;

use App\Ai\Drivers\Errors\ErrorMapper;
use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ProviderClient
{
    public function __construct(
        private readonly array $config,
        private readonly ErrorMapper $errors,
        private readonly array $headers = [],
    ) {}

    public function post(string $path, array $body, bool $stream = false): Response
    {
        return $this->send('POST', $path, $body, $stream);
    }

    public function send(string $method, string $path, array $body = [], bool $stream = false): Response
    {
        try {
            $response = $this->pending()
                ->withOptions($stream ? ['stream' => true] : [])
                ->send(strtoupper($method), $path, $this->payload($method, $body));
        } catch (ConnectionException $exception) {
            throw ModelRequestFailed::unreachable((string) ($this->config['base_url'] ?? ''), $exception);
        }

        if ($response->failed()) {
            throw $this->errors->fromResponse($response);
        }

        return $response;
    }

    private function payload(string $method, array $body): array
    {
        if (strtoupper($method) === 'GET') {
            return ['query' => $body];
        }

        return ['json' => $body];
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl((string) $this->config['base_url'])
            ->withHeaders($this->headers)
            ->acceptJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 2))
            ->timeout((int) ($this->config['timeout'] ?? 120));
    }
}
