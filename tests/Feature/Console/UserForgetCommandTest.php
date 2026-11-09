<?php

namespace Tests\Feature\Console;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class UserForgetCommandTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_it_deletes_the_users_conversation_content_everywhere_it_lives(): void
    {
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->addExchange($conversation, 'my secret', 'ok');
        UserMemory::factory()->forUser($user)->create();
        ToolCall::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'tool' => 'lookup_invoice', 'arguments' => [], 'decision' => 'allowed']);
        PendingToolCall::create(['user_id' => $user->id, 'tool' => 'refund', 'tool_call_id' => 'c', 'arguments' => [], 'summary' => 's', 'status' => 'pending', 'source' => 'web']);

        $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true])->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Message::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, UserMemory::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ToolCall::query()->count());
        $this->assertSame(0, PendingToolCall::query()->count());
    }

    public function test_it_anonymizes_model_calls_but_keeps_their_counts(): void
    {
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $call = ModelCall::create([
            'feature' => 'support.reply',
            'provider' => 'ollama',
            'model' => 'llama3.1:8b',
            'prompt_tokens' => 120,
            'completion_tokens' => 30,
            'finish_reason' => 'stop',
            'latency_ms' => 900,
            'conversation_id' => $conversation->id,
        ]);

        $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true])->assertSuccessful();

        $fresh = $call->fresh();
        $this->assertNull($fresh->conversation_id);
        $this->assertSame(120, $fresh->prompt_tokens);
    }

    public function test_it_leaves_other_users_data_alone(): void
    {
        $user = $this->userInTenant();
        $other = $this->userInTenant();
        $this->addExchange($this->conversationFor($user), 'mine', 'x');
        $kept = $this->conversationFor($other);
        $this->addExchange($kept, 'theirs', 'y');
        UserMemory::factory()->forUser($other)->create();

        $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true])->assertSuccessful();

        $this->assertSame([$kept->id], Conversation::query()->withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame(2, Message::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, UserMemory::query()->withoutGlobalScopes()->count());
    }

    public function test_it_deletes_nothing_without_confirm(): void
    {
        $user = $this->userInTenant();
        $this->addExchange($this->conversationFor($user), 'mine', 'x');

        $this->artisan('user:forget', ['user' => $user->id])->assertFailed();

        $this->assertSame(2, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_it_fails_for_an_unknown_user(): void
    {
        $this->artisan('user:forget', ['user' => 999999, '--confirm' => true])
            ->expectsOutputToContain('No user with that id.')
            ->assertFailed();
    }

    public function test_it_reports_what_it_deleted_and_logs_counts_without_content(): void
    {
        Log::spy();
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->addExchange($conversation, 'my secret', 'ok');

        $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true])
            ->expectsOutputToContain('Deleted: 1 conversations, 2 messages, 0 memories')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'user:forget'
            && $context['messages'] === 2
            && ! str_contains(json_encode($context), 'secret'));
    }
}
