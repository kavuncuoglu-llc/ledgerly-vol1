<?php

namespace App\Ai\Tools\Mcp;

interface McpClient
{
    public function listTools(): array;

    public function callTool(string $name, array $arguments): McpCallResult;
}
