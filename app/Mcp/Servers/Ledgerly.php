<?php

namespace App\Mcp\Servers;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Mcp\McpToolAdapter;
use App\Mcp\Prompts\SupportPromptMcp;
use App\Mcp\Prompts\TicketClassifyPromptMcp;
use App\Mcp\Resources\CustomerInvoicesTemplate;
use App\Mcp\Resources\HelpArticleResource;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Ledgerly')]
#[Version('1.0.0')]
#[Instructions('Read-mostly access to a Ledgerly account: customers, invoices, help center. Writes require approval in the Ledgerly app.')]
final class Ledgerly extends Server
{
    protected array $resources = [
        HelpArticleResource::class,
        CustomerInvoicesTemplate::class,
    ];

    protected array $prompts = [
        SupportPromptMcp::class,
        TicketClassifyPromptMcp::class,
    ];

    protected function boot(): void
    {
        $this->tools = app(ToolRegistry::class)
            ->native()
            ->map(fn (Tool $tool): McpToolAdapter => new McpToolAdapter($tool))
            ->all();
    }
}
