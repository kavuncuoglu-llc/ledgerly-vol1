<?php

namespace App\Ai\Llama;

use App\Ai\Native\LlamaEndpoint;
use App\Ai\Native\ProbeClient;
use App\Ai\Native\ServerReply;

final class LlamaServerClient extends ProbeClient
{
    public static function forProvider(array $providerConfig): self
    {
        return new self(
            baseUrl: LlamaEndpoint::nativeBaseUrl((string) ($providerConfig['base_url'] ?? '')),
            apiKey: (string) ($providerConfig['api_key'] ?? ''),
        );
    }

    public function health(): ServerReply
    {
        return $this->send('GET', '/health');
    }

    public function props(): ServerReply
    {
        return $this->send('GET', '/props');
    }

    public function propsWithoutKey(): ServerReply
    {
        return $this->send('GET', '/props', authenticated: false);
    }

    public function slots(): ServerReply
    {
        return $this->send('GET', '/slots');
    }

    public function metrics(): ServerReply
    {
        return $this->send('GET', '/metrics');
    }

    public function tokenize(string $text): ServerReply
    {
        return $this->send('POST', '/tokenize', ['content' => $text]);
    }

    public function applyTemplate(array $messages, int $timeoutSeconds): ServerReply
    {
        return $this->send('POST', '/apply-template', ['messages' => $messages], timeoutSeconds: $timeoutSeconds);
    }

    public function completion(array $body, int $timeoutSeconds): ServerReply
    {
        return $this->send('POST', '/completion', $body, timeoutSeconds: $timeoutSeconds);
    }

    public function chatCompletion(array $body, int $timeoutSeconds): ServerReply
    {
        return $this->send('POST', '/v1/chat/completions', $body, timeoutSeconds: $timeoutSeconds);
    }
}
