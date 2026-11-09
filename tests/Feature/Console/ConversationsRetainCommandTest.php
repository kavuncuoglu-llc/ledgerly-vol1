<?php

namespace Tests\Feature\Console;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\UserMemory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ConversationsRetainCommandTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_it_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command, 'conversations:retain'));

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events->first()->expression);
    }

    public function test_it_archives_closed_conversations_older_than_the_retention_period(): void
    {
        $conversation = $this->closedConversation();
        $this->addExchange($conversation, 'hello', 'hi');
        $conversation->update(['summary' => 'S']);
        $this->touchUpdatedAt($conversation, 91);

        $this->artisan('conversations:retain')->assertSuccessful();

        $fresh = $conversation->fresh();
        $this->assertSame(Conversation::STATUS_ARCHIVED, $fresh->status);
        $this->assertNull($fresh->summary);
        $this->assertSame(0, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_it_keeps_closed_conversations_inside_the_retention_period(): void
    {
        $conversation = $this->closedConversation();
        $this->addExchange($conversation, 'hello', 'hi');
        $this->touchUpdatedAt($conversation, 10);

        $this->artisan('conversations:retain')->assertSuccessful();

        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
        $this->assertSame(2, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_it_never_touches_open_conversations(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());
        $this->addExchange($conversation, 'hello', 'hi');
        $this->touchUpdatedAt($conversation, 400);

        $this->artisan('conversations:retain')->assertSuccessful();

        $this->assertTrue($conversation->fresh()->isOpen());
        $this->assertSame(2, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_it_covers_every_tenant_not_just_the_current_one(): void
    {
        $first = $this->closedConversation();
        $second = $this->closedConversation();
        $this->addExchange($first, 'a', 'b');
        $this->addExchange($second, 'c', 'd');
        $this->touchUpdatedAt($first, 100);
        $this->touchUpdatedAt($second, 100);

        $this->artisan('conversations:retain')->assertSuccessful();

        $this->assertSame(0, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_the_period_comes_from_config(): void
    {
        config()->set('ai.retention.closed_conversation_days', 5);
        $conversation = $this->closedConversation();
        $this->touchUpdatedAt($conversation, 6);

        $this->artisan('conversations:retain')->assertSuccessful();

        $this->assertSame(Conversation::STATUS_ARCHIVED, $conversation->fresh()->status);
    }

    public function test_it_expires_memories_unused_for_a_year(): void
    {
        $user = $this->userInTenant();
        $stale = UserMemory::factory()->forUser($user)->create(['last_used_at' => now()->subDays(400)]);
        $recent = UserMemory::factory()->forUser($user)->create(['last_used_at' => now()->subDays(30)]);
        $neverUsedButNew = UserMemory::factory()->forUser($user)->create(['last_used_at' => null]);
        $neverUsedAndOld = UserMemory::factory()->forUser($user)->create(['last_used_at' => null]);
        DB::table('user_memories')->where('id', $neverUsedAndOld->id)->update(['created_at' => now()->subDays(500)]);

        $this->artisan('conversations:retain')->assertSuccessful();

        $this->assertDatabaseMissing('user_memories', ['id' => $stale->id]);
        $this->assertDatabaseMissing('user_memories', ['id' => $neverUsedAndOld->id]);
        $this->assertDatabaseHas('user_memories', ['id' => $recent->id]);
        $this->assertDatabaseHas('user_memories', ['id' => $neverUsedButNew->id]);
    }

    private function closedConversation(): Conversation
    {
        return Conversation::factory()->forUser($this->userInTenant())->closed()->create();
    }

    private function touchUpdatedAt(Conversation $conversation, int $daysAgo): void
    {
        DB::table('conversations')
            ->where('id', $conversation->id)
            ->update(['updated_at' => now()->subDays($daysAgo)]);
    }
}
