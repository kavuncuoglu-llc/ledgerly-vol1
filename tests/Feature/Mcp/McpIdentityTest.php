<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Exceptions\McpAuthenticationRequired;
use App\Mcp\McpIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class McpIdentityTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_a_request_without_a_user_is_refused(): void
    {
        $this->expectException(McpAuthenticationRequired::class);

        app(McpIdentity::class)->contextFor(new Request);
    }

    public function test_the_stdio_user_is_ignored_outside_the_local_environment(): void
    {
        $user = $this->customerUser();
        config()->set('ai.mcp_stdio_user_email', $user->email);

        $this->expectException(McpAuthenticationRequired::class);

        app(McpIdentity::class)->contextFor(new Request);
    }

    public function test_the_stdio_user_is_used_in_the_local_environment_when_configured(): void
    {
        $user = $this->customerUser();
        config()->set('ai.mcp_stdio_user_email', $user->email);
        $this->app['env'] = 'local';

        $ctx = app(McpIdentity::class)->contextFor(new Request);

        $this->assertSame($user->id, $ctx->user->id);
        $this->assertSame('mcp', $ctx->source);
        $this->assertSame('stdio', $ctx->tokenName);
    }

    public function test_the_stdio_user_is_not_used_when_nothing_is_configured_even_locally(): void
    {
        $this->app['env'] = 'local';

        $this->expectException(McpAuthenticationRequired::class);

        app(McpIdentity::class)->contextFor(new Request);
    }
}
