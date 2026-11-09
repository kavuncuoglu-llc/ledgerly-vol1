<?php

namespace Tests\Feature\Console;

use App\Ai\Events\ReplyFailed;
use App\Models\PendingToolCall;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class RepliesExpirePausedCommandTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    private const TOKEN = '6f1b8c40-2c36-4a43-9e29-6e5b2b6f6a10';

    public function test_handle_expires_a_reply_paused_longer_than_the_limit_and_tells_the_browser(): void
    {
        Event::fake([ReplyFailed::class]);
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->paused(self::TOKEN)->create(['paused_at' => now()->subDays(8)]);
        $pending = $this->pendingFor($user);

        $this->artisan('replies:expire-paused')
            ->expectsOutput('Expired 1 paused replies.')
            ->assertSuccessful();

        $reply->refresh();
        $this->assertSame(Reply::STATUS_EXPIRED, $reply->status);
        $this->assertSame('This request expired before it was approved.', $reply->content);
        $this->assertSame(PendingToolCall::STATUS_EXPIRED, $pending->fresh()->status);
        Event::assertDispatched(ReplyFailed::class, fn (ReplyFailed $event): bool => $event->replyId === $reply->id);
    }

    public function test_handle_leaves_a_recently_paused_reply_alone(): void
    {
        Event::fake([ReplyFailed::class]);
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->paused(self::TOKEN)->create(['paused_at' => now()->subDays(6)]);
        $pending = $this->pendingFor($user);

        $this->artisan('replies:expire-paused')->expectsOutput('Expired 0 paused replies.');

        $this->assertSame(Reply::STATUS_PAUSED, $reply->fresh()->status);
        $this->assertSame(PendingToolCall::STATUS_PENDING, $pending->fresh()->status);
        Event::assertNotDispatched(ReplyFailed::class);
    }

    public function test_handle_ignores_replies_that_are_not_paused(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed()->create(['paused_at' => now()->subDays(30)]);

        $this->artisan('replies:expire-paused')->expectsOutput('Expired 0 paused replies.');

        $this->assertSame(Reply::STATUS_COMPLETED, $reply->fresh()->status);
    }

    public function test_handle_honours_the_configured_limit(): void
    {
        config()->set('ai.replies.paused_expiry_days', 1);
        $reply = Reply::factory()->forUser($this->userInTenant())->paused(self::TOKEN)->create(['paused_at' => now()->subDays(2)]);

        $this->artisan('replies:expire-paused')->expectsOutput('Expired 1 paused replies.');

        $this->assertSame(Reply::STATUS_EXPIRED, $reply->fresh()->status);
    }

    public function test_an_expired_approval_can_no_longer_be_approved(): void
    {
        $user = $this->userInTenant();
        Reply::factory()->forUser($user)->paused(self::TOKEN)->create(['paused_at' => now()->subDays(8)]);
        $pending = $this->pendingFor($user);
        $this->artisan('replies:expire-paused');

        $this->actingAs($user)->postJson("support/approve/{$pending->id}")->assertStatus(409);
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('replies:expire-paused');
    }

    private function pendingFor(User $user): PendingToolCall
    {
        return PendingToolCall::create([
            'user_id' => $user->id,
            'run_id' => self::TOKEN,
            'tool' => 'issue_refund',
            'tool_call_id' => 'call_1',
            'arguments' => [],
            'summary' => 'Refund $21.60',
            'status' => PendingToolCall::STATUS_PENDING,
        ]);
    }
}
