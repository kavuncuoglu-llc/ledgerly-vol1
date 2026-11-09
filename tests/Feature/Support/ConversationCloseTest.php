<?php

namespace Tests\Feature\Support;

use App\Ai\Memory\ExtractMemories;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ConversationCloseTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_close_marks_the_conversation_closed_and_queues_memory_extraction(): void
    {
        Queue::fake();
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);

        $response = $this->actingAs($user)->postJson("support/conversations/{$conversation->id}/close");

        $response->assertOk()->assertJson(['data' => ['id' => $conversation->id, 'status' => 'closed']]);
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
        Queue::assertPushed(ExtractMemories::class, fn (ExtractMemories $job): bool => $job->conversation->is($conversation));
    }

    public function test_close_rejects_a_guest_with_401(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());

        $this->postJson("support/conversations/{$conversation->id}/close")->assertUnauthorized();
    }

    public function test_close_rejects_another_user_in_the_same_tenant_with_403(): void
    {
        Queue::fake();
        $owner = $this->userInTenant();
        $colleague = User::factory()->forCustomer($owner->customer)->create();
        $conversation = $this->conversationFor($owner);

        $this->actingAs($colleague)->postJson("support/conversations/{$conversation->id}/close")->assertForbidden();

        $this->assertTrue($conversation->fresh()->isOpen());
        Queue::assertNothingPushed();
    }

    public function test_close_answers_404_for_a_conversation_in_another_tenant(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());

        $this->actingAs($this->userInTenant())->postJson("support/conversations/{$conversation->id}/close")->assertNotFound();
    }

    public function test_close_answers_404_for_an_unknown_conversation(): void
    {
        $this->actingAs($this->userInTenant())->postJson('support/conversations/999999/close')->assertNotFound();
    }

    public function test_close_answers_409_for_a_conversation_that_is_already_closed(): void
    {
        Queue::fake();
        $user = $this->userInTenant();
        $conversation = Conversation::factory()->forUser($user)->closed()->create();

        $this->actingAs($user)->postJson("support/conversations/{$conversation->id}/close")->assertStatus(409);

        Queue::assertNothingPushed();
    }
}
