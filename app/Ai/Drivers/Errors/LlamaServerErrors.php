<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\Response;

final class LlamaServerErrors implements ErrorMapper
{
    private const CONTEXT_OVERFLOW_TYPE = 'exceed_context_size_error';

    public function fromResponse(Response $response): ModelRequestFailed
    {
        if ($this->type($response) === self::CONTEXT_OVERFLOW_TYPE) {
            return new ContextTooLong("The model server answered HTTP {$response->status()}: {$response->body()}", $response->status());
        }

        return (new OpenAiErrors)->fromResponse($response);
    }

    private function type(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return '';
        }

        return (string) ($error['type'] ?? '');
    }
}
