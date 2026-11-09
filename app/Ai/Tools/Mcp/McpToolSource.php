<?php

namespace App\Ai\Tools\Mcp;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolSource;
use Closure;
use Throwable;

final class McpToolSource implements ToolSource
{
    public function __construct(
        private readonly array $servers,
        private readonly Closure $clientFor,
        private readonly ToolKind $defaultKind = ToolKind::WriteHighRisk,
    ) {}

    public function tools(): array
    {
        $tools = [];

        foreach ($this->servers as $server => $config) {
            $tools = [...$tools, ...$this->toolsOf($server, $config)];
        }

        return $tools;
    }

    private function toolsOf(string $server, array $config): array
    {
        try {
            $client = ($this->clientFor)($server, $config);

            return array_map(
                fn (array $definition): Tool => new McpTool(
                    client: $client,
                    server: $server,
                    definition: $definition,
                    kind: $this->kindFor($config, $definition['name']),
                ),
                $client->listTools(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function kindFor(array $config, string $toolName): ToolKind
    {
        $declared = $config['tool_kinds'][$toolName] ?? null;

        if ($declared === null) {
            return $this->defaultKind;
        }

        return ToolKind::tryFrom($declared) ?? $this->defaultKind;
    }
}
