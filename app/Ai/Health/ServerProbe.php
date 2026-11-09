<?php

namespace App\Ai\Health;

use App\Ai\Native\ProbeClient;
use App\Ai\Native\ServerReply;
use App\Ai\Native\TritonEndpoint;

final class ServerProbe extends ProbeClient
{
    public static function forProvider(array $providerConfig): self
    {
        $triton = TritonEndpoint::isTriton($providerConfig);

        return new self(
            baseUrl: rtrim((string) ($providerConfig['base_url'] ?? ''), '/'),
            apiKey: $triton
                ? ''
                : (string) ($providerConfig['api_key'] ?? ''),
            headers: $triton
                ? TritonEndpoint::authHeaders($providerConfig)
                : [],
        );
    }

    public function models(int $timeoutSeconds): ServerReply
    {
        return $this->send('GET', '/models', timeoutSeconds: $timeoutSeconds);
    }

    public function completion(array $body, int $timeoutSeconds): ServerReply
    {
        return $this->send('POST', '/chat/completions', $body, timeoutSeconds: $timeoutSeconds);
    }
}
