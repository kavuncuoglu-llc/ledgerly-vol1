<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class McpToken extends Command
{
    protected $signature = 'ledgerly:mcp-token {email : Email of the user the token acts as} {name : A label for the client, shown in tool_calls.token_name}';

    protected $description = 'Create a Sanctum personal access token for an MCP client and print it once';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return Command::FAILURE;
        }

        $token = $user->createToken((string) $this->argument('name'));

        $this->line($token->plainTextToken);

        return Command::SUCCESS;
    }
}
