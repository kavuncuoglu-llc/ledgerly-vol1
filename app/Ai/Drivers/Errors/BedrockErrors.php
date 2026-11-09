<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use Illuminate\Http\Client\Response;

final class BedrockErrors implements ErrorMapper
{
    public function fromResponse(Response $response): ModelRequestFailed
    {
        $status = $response->status();

        $body = $response->body();

        $type = $this->typeOf($response);

        $message = "The model server answered HTTP {$status}: {$body}";

        if ($type === 'ThrottlingException') {
            return new RateLimited($message, $status, RetryAfter::seconds($response));
        }

        if ($type === 'ModelNotReadyException') {
            return new ProviderUnavailable($message, $status);
        }

        if ($type === 'ValidationException') {
            return $this->validationFailure($message, $status, (string) $response->json('message', ''));
        }

        return ModelRequestFailed::rejected($status, $body, RetryAfter::seconds($response));
    }

    public function fromStreamException(string $type, string $payload): ModelRequestFailed
    {
        $message = "The model server sent a stream error [{$type}]: {$payload}";

        return match ($type) {
            'throttlingException' => new RateLimited($message),
            'validationException' => new InvalidRequest($message),
            'serviceUnavailableException', 'modelStreamErrorException', 'internalServerException', 'modelTimeoutException' => new ProviderUnavailable($message),
            default => new ModelRequestFailed($message),
        };
    }

    private function typeOf(Response $response): string
    {
        $header = (string) $response->header('x-amzn-ErrorType');

        if ($header !== '') {
            return explode(':', $header)[0];
        }

        return (string) $response->json('__type', '');
    }

    private function validationFailure(string $message, int $status, string $detail): ModelRequestFailed
    {
        if (preg_match('/too long|too many tokens|exceeds the (maximum|limit)|context (length|window)/i', $detail) === 1) {
            return new ContextTooLong($message, $status);
        }

        return new InvalidRequest($message, $status);
    }
}
