<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use Illuminate\Http\Client\Response;

final class GeminiErrors implements ErrorMapper
{
    public function fromResponse(Response $response): ModelRequestFailed
    {
        $status = $response->status();

        $body = $response->body();

        $code = (string) $response->json('error.status', '');

        $message = "The model server answered HTTP {$status}: {$body}";

        if ($code === 'RESOURCE_EXHAUSTED') {
            return new RateLimited($message, $status, RetryAfter::seconds($response));
        }

        if ($code === 'UNAVAILABLE') {
            return new ProviderUnavailable($message, $status);
        }

        if ($code === 'INVALID_ARGUMENT' && $this->isContextOverflow((string) $response->json('error.message', ''))) {
            return new ContextTooLong($message, $status);
        }

        if ($code === 'INVALID_ARGUMENT') {
            return new InvalidRequest($message, $status);
        }

        return ModelRequestFailed::rejected($status, $body, RetryAfter::seconds($response));
    }

    private function isContextOverflow(string $message): bool
    {
        return preg_match('/token count|exceeds the maximum|input token/i', $message) === 1;
    }
}
