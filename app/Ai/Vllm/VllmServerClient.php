<?php

namespace App\Ai\Vllm;

use App\Ai\Native\ProbeClient;
use App\Ai\Native\ServerReply;
use App\Ai\Native\VllmEndpoint;

final class VllmServerClient extends ProbeClient
{
    public static function forProvider(array $providerConfig): self
    {
        return new self(
            baseUrl: VllmEndpoint::nativeBaseUrl((string) ($providerConfig['base_url'] ?? '')),
            apiKey: (string) ($providerConfig['api_key'] ?? ''),
        );
    }

    public function health(): ServerReply
    {
        return $this->send('GET', '/health');
    }

    public function version(): ServerReply
    {
        return $this->send('GET', '/version');
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
}
