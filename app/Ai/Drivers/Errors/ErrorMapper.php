<?php

namespace App\Ai\Drivers\Errors;

use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\Response;

interface ErrorMapper
{
    public function fromResponse(Response $response): ModelRequestFailed;
}
