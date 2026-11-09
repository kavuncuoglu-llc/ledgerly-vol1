<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\Mcp\McpCallResult;
use App\Ai\Tools\Mcp\McpClient;
use App\Ai\Tools\Mcp\McpToolSource;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

class McpToolSourceTest extends TestCase
{
    public function test_tools_are_prefixed_with_the_server_name_and_keep_the_remote_schema(): void
    {
        $source = $this->source(['query', 'insert_row']);

        $tools = $source->tools();

        $this->assertSame(['postgres_query', 'postgres_insert_row'], array_map(fn ($tool): string => $tool->name(), $tools));
        $this->assertSame('object', $tools[0]->parameters()['type']);
        $this->assertSame('Remote query', $tools[0]->description());
    }

    public function test_only_declared_read_tools_are_reads_and_everything_else_needs_approval(): void
    {
        $source = $this->source(['query', 'insert_row', 'unlisted_tool']);

        $kinds = array_map(fn ($tool): ToolKind => $tool->kind(), $source->tools());

        $this->assertSame([ToolKind::Read, ToolKind::WriteLowRisk, ToolKind::WriteHighRisk], $kinds);
    }

    public function test_handle_calls_the_remote_tool_with_the_model_arguments(): void
    {
        $client = $this->client(['query']);
        $source = $this->source(['query'], $client);
        $tool = $source->tools()[0];

        $result = $tool->handle($tool->hydrate(['sql' => 'select 1']), new ToolContext(user: new User));

        $this->assertSame(['query', ['sql' => 'select 1']], $client->calls[0]);
        $this->assertSame(['ok' => true, 'text' => 'remote answer'], $result->toArray());
    }

    public function test_a_remote_error_becomes_a_generic_tool_error(): void
    {
        $client = $this->client(['query'], isError: true);
        $tool = $this->source(['query'], $client)->tools()[0];

        $result = $tool->handle($tool->hydrate([]), new ToolContext(user: new User));

        $this->assertFalse($result->ok);
        $this->assertSame('tool failed', $result->error);
    }

    public function test_a_server_that_cannot_be_reached_contributes_no_tools_and_is_reported(): void
    {
        Exceptions::fake();
        $source = new McpToolSource(
            servers: ['down' => ['transport' => 'stdio', 'command' => ['nope']]],
            clientFor: fn () => throw new RuntimeException('connection refused'),
        );

        $tools = $source->tools();

        $this->assertSame([], $tools);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'connection refused');
    }

    public function test_an_empty_server_list_adds_nothing_to_the_registry(): void
    {
        $registry = (new ToolRegistry)->addSource(new McpToolSource([], fn () => throw new RuntimeException));

        $this->assertSame([], $registry->definitions());
    }

    private function source(array $names, ?McpClient $client = null): McpToolSource
    {
        $client ??= $this->client($names);

        return new McpToolSource(
            servers: [
                'postgres' => [
                    'transport' => 'stdio',
                    'command' => ['npx', 'server-postgres'],
                    'tool_kinds' => [
                        'query' => 'read',
                        'insert_row' => 'write_low_risk',
                    ],
                ],
            ],
            clientFor: fn () => $client,
        );
    }

    private function client(array $names, bool $isError = false): object
    {
        return new class($names, $isError) implements McpClient
        {
            public array $calls = [];

            public function __construct(private array $names, private bool $isError) {}

            public function listTools(): array
            {
                return array_map(fn (string $name): array => [
                    'name' => $name,
                    'description' => 'Remote '.$name,
                    'inputSchema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']]],
                ], $this->names);
            }

            public function callTool(string $name, array $arguments): McpCallResult
            {
                $this->calls[] = [$name, $arguments];

                return new McpCallResult('remote answer', $this->isError);
            }
        };
    }
}
