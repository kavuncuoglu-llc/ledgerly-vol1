<?php

namespace App\Ai\Triton;

use App\Ai\Native\ProbeClient;
use App\Ai\Native\ServerReply;
use App\Ai\Native\TritonEndpoint;

final class TritonServerClient extends ProbeClient
{
    public static function forProvider(array $providerConfig): self
    {
        return new self(
            baseUrl: TritonEndpoint::nativeBaseUrl((string) ($providerConfig['base_url'] ?? '')),
            headers: TritonEndpoint::authHeaders($providerConfig),
        );
    }

    public static function kserveForProvider(array $providerConfig): self
    {
        return new self(
            baseUrl: rtrim((string) ($providerConfig['kserve_url'] ?? ''), '/'),
        );
    }

    public function hasKserveUrl(): bool
    {
        return $this->baseUrl !== '';
    }

    public function frontendReady(): ServerReply
    {
        return $this->send('GET', '/health/ready');
    }

    public function models(): ServerReply
    {
        return $this->send('GET', '/v1/models');
    }

    public function modelsWithoutKey(): ServerReply
    {
        return $this->send('GET', '/v1/models', authenticated: false);
    }

    public function metrics(): ServerReply
    {
        return $this->send('GET', '/metrics');
    }

    public function chatCompletion(array $body, int $timeoutSeconds): ServerReply
    {
        return $this->send('POST', '/v1/chat/completions', $body, timeoutSeconds: $timeoutSeconds);
    }

    public function serverLive(): ServerReply
    {
        return $this->send('GET', '/v2/health/live');
    }

    public function serverReady(): ServerReply
    {
        return $this->send('GET', '/v2/health/ready');
    }

    public function serverMetadata(): ServerReply
    {
        return $this->send('GET', '/v2');
    }

    public function repositoryIndex(): ServerReply
    {
        return $this->send('POST', '/v2/repository/index', ['ready' => false]);
    }

    public function modelReady(string $model): ServerReply
    {
        return $this->send('GET', '/v2/models/'.rawurlencode($model).'/ready');
    }

    public function modelConfig(string $model): ServerReply
    {
        return $this->send('GET', '/v2/models/'.rawurlencode($model).'/config');
    }
}
