<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class McpTokenCommandTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    public function test_handle_prints_a_token_that_belongs_to_the_user_under_the_given_name(): void
    {
        $user = $this->customerUser();

        $exitCode = Artisan::call('ledgerly:mcp-token', ['email' => $user->email, 'name' => 'cursor-jeremy']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('|', trim(Artisan::output()));
        $this->assertSame('cursor-jeremy', $user->tokens()->sole()->name);
    }

    public function test_handle_fails_for_an_unknown_email(): void
    {
        $this->artisan('ledgerly:mcp-token', ['email' => 'nobody@example.com', 'name' => 'x'])
            ->expectsOutputToContain('No user with that email.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
