<?php

namespace App\Ai\Drivers\Support;

use App\Ai\Drivers\Errors\BedrockErrors;
use App\Ai\Drivers\Support\Aws\CredentialResolver;
use App\Ai\Drivers\Support\Aws\SigV4Signer;
use App\Ai\Exceptions\ModelRequestFailed;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class BedrockClient
{
    private const SERVICE = 'bedrock';

    public function __construct(
        private readonly array $config,
        private readonly BedrockErrors $errors = new BedrockErrors,
        private readonly SigV4Signer $signer = new SigV4Signer,
        private readonly CredentialResolver $credentials = new CredentialResolver,
    ) {}

    public function converse(string $modelId, array $body, bool $stream = false): Response
    {
        $operation = $stream
            ? 'converse-stream'
            : 'converse';

        return $this->send('POST', '/model/'.rawurlencode($modelId)."/{$operation}", $body, $stream);
    }

    public function send(string $method, string $path, array $body = [], bool $stream = false): Response
    {
        $baseUrl = $this->baseUrl();

        $payload = strtoupper($method) === 'GET'
            ? ''
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = $this->signer->sign(
            method: $method,
            host: $this->hostOf($baseUrl),
            path: $path,
            body: $payload,
            credentials: $this->credentials->resolve($this->config),
            region: (string) $this->config['region'],
            service: self::SERVICE,
            at: new DateTimeImmutable('now'),
        );

        try {
            $response = Http::withHeaders($headers)
                ->withBody($payload, 'application/json')
                ->acceptJson()
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 2))
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->withOptions($stream ? ['stream' => true] : [])
                ->send(strtoupper($method), $baseUrl.$path);
        } catch (ConnectionException $exception) {
            throw ModelRequestFailed::unreachable($baseUrl, $exception);
        }

        if ($response->failed()) {
            throw $this->errors->fromResponse($response);
        }

        return $response;
    }

    public function errors(): BedrockErrors
    {
        return $this->errors;
    }

    private function baseUrl(): string
    {
        $configured = (string) ($this->config['base_url'] ?? '');

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return "https://bedrock-runtime.{$this->config['region']}.amazonaws.com";
    }

    private function hostOf(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);

        $host = (string) ($parts['host'] ?? '');

        if (isset($parts['port'])) {
            return "{$host}:{$parts['port']}";
        }

        return $host;
    }
}
