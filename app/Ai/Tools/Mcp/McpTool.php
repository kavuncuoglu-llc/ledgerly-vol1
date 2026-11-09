<?php

namespace App\Ai\Tools\Mcp;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;

final class McpTool implements Tool
{
    public function __construct(
        private readonly McpClient $client,
        private readonly string $server,
        private readonly array $definition,
        private readonly ToolKind $kind,
    ) {}

    public function name(): string
    {
        return $this->server.'_'.$this->definition['name'];
    }

    public function description(): string
    {
        return $this->definition['description'] ?? '';
    }

    public function arguments(): string
    {
        return McpArguments::class;
    }

    public function parameters(): array
    {
        return $this->definition['inputSchema'] ?? [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function validate(array $arguments): array
    {
        return $arguments;
    }

    public function hydrate(array $validated): object
    {
        return McpArguments::fromArray($validated);
    }

    public function kind(): ToolKind
    {
        return $this->kind;
    }

    public function perConversationLimit(): int
    {
        return 10;
    }

    public function describeIntent(array $arguments, ToolContext $ctx): string
    {
        return "Call {$this->server} tool {$this->definition['name']} with ".json_encode($arguments);
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $result = $this->client->callTool($this->definition['name'], $args->values);

        if ($result->isError) {
            return ToolResult::error('tool failed', [
                'message' => 'The external tool reported an error.',
            ]);
        }

        return ToolResult::ok([
            'text' => $result->text,
        ]);
    }
}
