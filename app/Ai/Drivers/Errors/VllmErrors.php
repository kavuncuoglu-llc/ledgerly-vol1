<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\Response;

final class VllmErrors implements ErrorMapper
{
    private const CONTEXT_OVERFLOW_PHRASE = 'maximum context length is';

    public function fromResponse(Response $response): ModelRequestFailed
    {
        if ($this->isContextOverflow($response)) {
            return new ContextTooLong("The model server answered HTTP {$response->status()}: {$response->body()}", $response->status());
        }

        return (new OpenAiErrors)->fromResponse($response);
    }

    private function isContextOverflow(Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        return str_contains($this->message($response), self::CONTEXT_OVERFLOW_PHRASE);
    }

    private function message(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return '';
        }

        return (string) ($error['message'] ?? '');
    }
}
