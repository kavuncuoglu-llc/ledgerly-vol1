<?php

namespace Tests\Feature\Ai;

use App\Ai\Conversations\ConversationStore;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UserMemory;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ConversationIsolationTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_history_never_leaks_across_tenants(): void
    {
        $userA = $this->userInTenant();
        $userB = $this->userInTenant();
        $convA = $this->conversationFor($userA);
        $convB = $this->conversationFor($userB);
        $this->addExchange($convA, 'secret-alpha-4471', 'alpha reply');
        $this->addExchange($convB, 'secret-beta-9920', 'beta reply');

        $this->actingAs($userB);
        $history = app(ConversationStore::class)->history($convB->fresh(), budgetTokens: 4000);

        $this->assertStringNotContainsString('alpha', json_encode($history));
        $this->assertStringContainsString('beta', json_encode($history));
        $this->expectException(ModelNotFoundException::class);
        Conversation::findOrFail($convA->id);
    }

    public function test_history_of_another_tenants_conversation_is_empty_even_when_asked_for_by_id(): void
    {
        $userA = $this->userInTenant();
        $convA = $this->conversationFor($userA);
        $this->addExchange($convA, 'secret-alpha-4471', 'alpha reply');

        $this->actingAs($this->userInTenant());
        $history = app(ConversationStore::class)->history($convA->fresh(), budgetTokens: 4000);

        $this->assertSame([], $history);
    }

    public function test_messages_are_scoped_through_their_conversations_tenant(): void
    {
        $userA = $this->userInTenant();
        $userB = $this->userInTenant();
        $this->addExchange($this->conversationFor($userA), 'a1', 'a2');
        $this->addExchange($this->conversationFor($userB), 'b1', 'b2');

        $this->actingAs($userB);

        $this->assertSame(['b1', 'b2'], Message::query()->orderBy('seq')->pluck('content')->all());
    }

    public function test_conversation_queries_return_only_the_current_tenants_rows(): void
    {
        $userA = $this->userInTenant();
        $userB = $this->userInTenant();
        $this->conversationFor($userA);
        $mine = $this->conversationFor($userB);

        $this->actingAs($userB);

        $this->assertSame([$mine->id], Conversation::query()->pluck('id')->all());
    }

    public function test_memories_are_scoped_by_tenant(): void
    {
        $userA = $this->userInTenant();
        $userB = $this->userInTenant();
        UserMemory::factory()->forUser($userA)->create(['key' => 'role', 'value' => 'secret-alpha']);
        UserMemory::factory()->forUser($userB)->create(['key' => 'role', 'value' => 'beta']);

        $this->actingAs($userB);

        $this->assertSame(['beta'], UserMemory::query()->pluck('value')->all());
    }

    public function test_the_scope_applies_to_relationship_loads(): void
    {
        $userA = $this->userInTenant();
        $convA = $this->conversationFor($userA);
        $this->addExchange($convA, 'secret-alpha-4471', 'alpha');

        $this->actingAs($this->userInTenant());

        $this->assertCount(0, $convA->fresh()->messages);
    }

    public function test_an_explicit_tenant_context_overrides_the_logged_in_user_for_the_block_only(): void
    {
        $userA = $this->userInTenant();
        $userB = $this->userInTenant();
        $convA = $this->conversationFor($userA);
        $this->actingAs($userB);

        $seenInside = app(CurrentTenant::class)->runAs($userA->customer_id, fn () => Conversation::query()->count());
        $seenAfter = Conversation::query()->count();

        $this->assertSame(1, $seenInside);
        $this->assertSame(0, $seenAfter);
        $this->assertSame($convA->tenant_id, $userA->customer_id);
    }

    public function test_nested_tenant_contexts_restore_the_outer_tenant(): void
    {
        $userA = $this->userInTenant();
        $userB = $this->userInTenant();
        $tenant = app(CurrentTenant::class);

        $inner = $tenant->runAs($userA->customer_id, function () use ($tenant, $userB): array {
            $nested = $tenant->runAs($userB->customer_id, fn () => $tenant->id());

            return [$nested, $tenant->id()];
        });

        $this->assertSame([$userB->customer_id, $userA->customer_id], $inner);
        $this->assertNull($tenant->id());
    }

    public function test_the_tenant_context_is_restored_when_the_block_throws(): void
    {
        $tenant = app(CurrentTenant::class);

        try {
            $tenant->runAs(5, fn () => throw new ModelNotFoundException);
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($tenant->id());
    }
}
