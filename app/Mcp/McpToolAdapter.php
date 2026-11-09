<?php

namespace App\Mcp;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Ai\Tools\ToolRunner;
use App\Mcp\Exceptions\McpAuthenticationRequired;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as ServerTool;

final class McpToolAdapter extends ServerTool
{
    public function __construct(private readonly Tool $tool) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function title(): string
    {
        return Str::headline($this->tool->name());
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->tool->parameters(),
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => $this->tool->kind() === ToolKind::Read,
            ],
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $ctx = app(McpIdentity::class)->contextFor($request);
        } catch (McpAuthenticationRequired $exception) {
            return Response::error($exception->getMessage());
        }

        $result = app(ToolRunner::class)->executeOne(
            tool: $this->tool,
            args: $request->all(),
            ctx: $ctx->forToolCall('mcp_'.Str::uuid()),
        );

        return $this->responseFor($result);
    }

    private function responseFor(ToolResult $result): Response
    {
        if ($result->isPending()) {
            return Response::text("Awaiting approval in Ledgerly: {$result->approvalUrl}");
        }

        if (! $result->ok) {
            return Response::error($result->toJson());
        }

        return Response::json($result->toArray());
    }
}
