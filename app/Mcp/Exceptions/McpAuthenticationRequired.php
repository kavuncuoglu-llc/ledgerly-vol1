<?php

namespace App\Mcp\Exceptions;

use RuntimeException;

final class McpAuthenticationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Authentication required: send a Ledgerly personal access token as a bearer token.');
    }
}
