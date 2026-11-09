<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\Response;

final class OpenAiErrors implements ErrorMapper
{
    public function fromResponse(Response $response): ModelRequestFailed
    {
        return ModelRequestFailed::rejected(
            $response->status(),
            $response->body(),
            RetryAfter::seconds($response),
        );
    }
}
