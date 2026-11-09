<?php

namespace Tests\Feature\Ai\Replies;

use App\Models\Reply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ReplyChannelAuthorizationTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        config()->set('broadcasting.connections.reverb.options.host', 'localhost');

        require base_path('routes/channels.php');
    }

    public function test_the_owner_may_subscribe_to_the_private_reply_channel(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->create();

        $response = $this->actingAs($user)->postJson('broadcasting/auth', $this->subscription($reply));

        $response->assertOk();
        $this->assertStringStartsWith('test-key:', $response->json('auth'));
    }

    public function test_another_user_in_the_same_tenant_may_not_subscribe(): void
    {
        $owner = $this->userInTenant();
        $reply = Reply::factory()->forUser($owner)->create();

        $this->actingAs($this->userInTenant($owner->customer))
            ->postJson('broadcasting/auth', $this->subscription($reply))
            ->assertForbidden();
    }

    public function test_a_user_in_another_tenant_may_not_subscribe(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create();

        $this->actingAs($this->userInTenant())
            ->postJson('broadcasting/auth', $this->subscription($reply))
            ->assertForbidden();
    }

    public function test_a_guest_may_not_subscribe(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create();

        $this->postJson('broadcasting/auth', $this->subscription($reply))->assertUnauthorized();
    }

    public function test_an_unknown_reply_channel_is_refused(): void
    {
        $this->actingAs($this->userInTenant())
            ->postJson('broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-reply.00000000-0000-0000-0000-000000000000',
            ])
            ->assertForbidden();
    }

    private function subscription(Reply $reply): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => "private-reply.{$reply->id}",
        ];
    }
}
