<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use Illuminate\Http\Client\Response;

final class AnthropicErrors implements ErrorMapper
{
    public function fromResponse(Response $response): ModelRequestFailed
    {
        $status = $response->status();

        $body = $response->body();

        $type = (string) $response->json('error.type', '');

        $message = "The model server answered HTTP {$status}: {$body}";

        if ($type === 'overloaded_error') {
            return new ProviderUnavailable($message, $status);
        }

        if ($type === 'invalid_request_error' && $this->isContextOverflow((string) $response->json('error.message', ''))) {
            return new ContextTooLong($message, $status);
        }

        return ModelRequestFailed::rejected($status, $body, RetryAfter::seconds($response));
    }

    private function isContextOverflow(string $message): bool
    {
        return preg_match('/prompt is too long|exceed.*context|too many tokens/i', $message) === 1;
    }
}
