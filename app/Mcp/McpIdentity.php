<?php

namespace App\Mcp;

use App\Ai\Tools\ToolContext;
use App\Mcp\Exceptions\McpAuthenticationRequired;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class McpIdentity
{
    public const STDIO_TOKEN_NAME = 'stdio';

    public function contextFor(Request $request): ToolContext
    {
        $user = $request->user() ?? $this->localStdioUser();

        if (! $user instanceof User) {
            throw new McpAuthenticationRequired;
        }

        return new ToolContext(
            user: $user,
            conversationId: null,
            source: ToolContext::SOURCE_MCP,
            tokenName: $this->tokenNameOf($user),
        );
    }

    private function tokenNameOf(User $user): string
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->name;
        }

        return self::STDIO_TOKEN_NAME;
    }

    private function localStdioUser(): User|false
    {
        if (! app()->environment('local')) {
            return false;
        }

        $email = config('ai.mcp_stdio_user_email');

        if (! $email) {
            return false;
        }

        return User::query()->where('email', $email)->first() ?? false;
    }
}
