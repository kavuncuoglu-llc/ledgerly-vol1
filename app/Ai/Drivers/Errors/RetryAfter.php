<?php

namespace App\Ai\Drivers\Errors;

use Illuminate\Http\Client\Response;

final class RetryAfter
{
    public static function seconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if (! is_numeric($header)) {
            return null;
        }

        return (int) $header;
    }
}
