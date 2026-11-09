<?php

namespace App\Ai\Http;

use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ModelEndpoint
{
    public function post(string $path, array $body): Response
    {
        try {
            $response = $this->request()->post($path, $body);
        } catch (ConnectionException $exception) {
            throw ModelRequestFailed::unreachable(config('ai.base_url'), $exception);
        }

        if ($response->failed()) {
            throw ModelRequestFailed::rejected($response->status(), $response->body());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('ai.base_url'))
            ->withToken(config('ai.api_key'))
            ->acceptJson()
            ->connectTimeout(config('ai.connect_timeout'))
            ->timeout(config('ai.timeout'));
    }
}
