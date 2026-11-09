<?php

namespace App\Ai\Tools\Mcp;

use Laravel\Mcp\Client;

final class LaravelMcpClient implements McpClient
{
    public function __construct(private readonly Client $client) {}

    public function listTools(): array
    {
        return $this->client
            ->tools()
            ->map(fn ($tool): array => [
                'name' => $tool->name,
                'description' => (string) $tool->description,
                'inputSchema' => $tool->inputSchema,
            ])
            ->values()
            ->all();
    }

    public function callTool(string $name, array $arguments): McpCallResult
    {
        $result = $this->client->callTool($name, $arguments);

        return new McpCallResult(
            text: $result->text(),
            isError: $result->isError,
        );
    }
}
