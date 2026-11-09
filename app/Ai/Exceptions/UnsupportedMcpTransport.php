<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnsupportedMcpTransport extends RuntimeException
{
    public function __construct(string $server, string $transport)
    {
        parent::__construct("MCP server [{$server}] uses transport [{$transport}]; use stdio or http.");
    }
}
