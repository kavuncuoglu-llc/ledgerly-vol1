<?php

namespace Tests\Feature\Support;

use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class SupportConversationTest extends TestCase
{
    use BuildsConversations;
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
    }

    public function test_ask_without_a_conversation_id_starts_one_and_returns_its_id(): void
    {
        Http::fake(['*' => Http::response($this->completion('Hello'))]);
        $user = $this->userInTenant();

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Hi']);

        $conversation = Conversation::query()->sole();
        $response->assertOk()->assertJson(['conversation_id' => $conversation->id]);
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertSame($user->customer_id, $conversation->tenant_id);
        $this->assertSame(SupportPrompt::VERSION, $conversation->prompt_version);
    }

    public function test_ask_stores_the_user_turn_and_the_assistant_reply_with_token_counts(): void
    {
        Http::fake(['*' => Http::response($this->completion('Hello there'))]);

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        $messages = Message::query()->withoutGlobalScopes()->orderBy('seq')->get();
        $this->assertSame(['user', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame(['Hi', 'Hello there'], $messages->pluck('content')->all());
        $this->assertSame([1, 2], $messages->pluck('seq')->all());
        $this->assertGreaterThan(0, $messages->first()->tokens);
    }

    public function test_ask_links_the_assistant_reply_to_its_model_call(): void
    {
        Http::fake(['*' => Http::response($this->completion('Hello'))]);

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        $reply = Message::query()->withoutGlobalScopes()->where('role', 'assistant')->sole();
        $this->assertSame(ModelCall::query()->sole()->id, $reply->model_call_id);
    }

    public function test_ask_populates_conversation_id_on_model_calls(): void
    {
        Http::fake(['*' => Http::response($this->completion('Hello'))]);

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        $this->assertSame(Conversation::query()->sole()->id, ModelCall::query()->sole()->conversation_id);
    }

    public function test_second_turn_resends_the_first_exchange_as_history_before_the_new_question(): void
    {
        Http::fake(['*' => Http::response($this->completion('March one is overdue.'))]);
        $user = $this->userInTenant();
        $first = $this->actingAs($user)->postJson('support/ask', ['question' => 'Why is my March invoice late?']);
        $conversationId = $first->json('conversation_id');

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'What about the April one?',
            'conversation_id' => $conversationId,
        ])->assertOk()->assertJson(['conversation_id' => $conversationId]);

        $secondRequest = Http::recorded()[1][0];
        $roles = array_column($secondRequest['messages'], 'role');
        $this->assertSame(['system', 'user', 'assistant', 'user'], $roles);
        $this->assertSame('Why is my March invoice late?', $secondRequest['messages'][1]['content']);
        $this->assertSame('What about the April one?', $secondRequest['messages'][3]['content']);
        $this->assertSame($conversationId, ModelCall::query()->latest('id')->first()->conversation_id);
        $this->assertSame(4, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_second_turn_sends_the_summary_as_a_system_note_ahead_of_the_history(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->addExchange($conversation, 'old question', 'old reply');
        $this->addExchange($conversation, 'recent question', 'recent reply');
        $conversation->update([
            'summary' => 'Problem: INV-4471 disputed',
            'summary_through_seq' => 2,
        ]);

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'Any news?',
            'conversation_id' => $conversation->id,
        ])->assertOk();

        $messages = Http::recorded()[0][0]['messages'];
        $this->assertSame(['system', 'system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('Problem: INV-4471 disputed', $messages[1]['content']);
        $this->assertSame('recent question', $messages[2]['content']);
    }

    public function test_ask_injects_the_users_memories_into_the_system_message(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = $this->userInTenant();
        UserMemory::factory()->forUser($user)->create(['key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner']);

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Hi'])->assertOk();

        $system = Http::recorded()[0][0]['messages'][0]['content'];
        $this->assertStringContainsString("What you know about this customer (they can edit this in Settings):\n- role: bookkeeper for Acme Studios, not the owner", $system);
    }

    public function test_ask_marks_the_injected_memories_as_used(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = $this->userInTenant();
        $memory = UserMemory::factory()->forUser($user)->create(['last_used_at' => null]);

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Hi']);

        $this->assertNotNull($memory->fresh()->last_used_at);
    }

    public function test_ask_stores_a_tool_exchange_verbatim_and_replays_it_in_the_next_turn(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $call = $this->toolCall('call_8a1', 'lookup_invoice', ['invoice_number' => 'INV-4471', 'month' => null]);
        Http::fake(['*' => Http::sequence()
            ->push($this->toolCallResponse([$call]))
            ->push($this->answerResponse('INV-4471 is overdue.'))
            ->push($this->answerResponse('Still overdue.')),
        ]);
        $first = $this->actingAs($user)->postJson('support/ask', ['question' => 'Look up 4471']);

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'And now?',
            'conversation_id' => $first->json('conversation_id'),
        ]);

        $stored = Message::query()->withoutGlobalScopes()->orderBy('seq')->take(4)->get();
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], $stored->pluck('role')->all());
        $this->assertSame([$call], $stored[1]->tool_calls);
        $this->assertSame('call_8a1', $stored[2]->tool_call_id);
        $replayed = Http::recorded()[2][0]['messages'];
        $this->assertSame([$call], $replayed[2]['tool_calls']);
        $this->assertSame('call_8a1', $replayed[3]['tool_call_id']);
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], array_column(array_slice($replayed, 1, 4), 'role'));
    }

    public function test_tool_calls_rows_carry_the_conversation_id(): void
    {
        $user = $this->customerUser();
        Http::fake(['*' => Http::sequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '1', 'month' => null])]))
            ->push($this->answerResponse('none')),
        ]);

        $this->actingAs($user)->postJson('support/ask', ['question' => 'look up 1']);

        $this->assertSame(Conversation::query()->sole()->id, ToolCall::query()->sole()->conversation_id);
    }

    public function test_the_per_conversation_tool_limit_counts_calls_across_turns_of_one_conversation(): void
    {
        $user = $this->customerUser();
        $limit = 10;
        $conversation = $this->conversationFor($user);

        foreach (range(1, $limit) as $number) {
            ToolCall::create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'tool' => 'lookup_invoice',
                'arguments' => [],
                'decision' => ToolCall::DECISION_ALLOWED,
            ]);
        }
        Http::fake(['*' => Http::sequence()
            ->push($this->toolCallResponse([$this->toolCall('call_9', 'lookup_invoice', ['invoice_number' => '1', 'month' => null])]))
            ->push($this->answerResponse('limit hit')),
        ]);

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'again',
            'conversation_id' => $conversation->id,
        ])->assertOk();

        $this->assertSame(
            ToolCall::DECISION_DENIED,
            ToolCall::query()->where('tool_call_id', 'call_9')->sole()->decision,
        );
    }

    public function test_ask_dispatches_the_background_job_when_unsummarized_tokens_exceed_the_allowance(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->addExchange($conversation, 'big', 'big', tokensEach: 5000);

        $this->actingAs($user)->postJson('support/ask', [
            'question' => 'more',
            'conversation_id' => $conversation->id,
        ]);

        Queue::assertPushed(SummarizeConversation::class, fn (SummarizeConversation $job): bool => $job->conversation->is($conversation)
            && $job->allowance > 0);
    }

    public function test_ask_does_not_dispatch_the_job_for_a_short_conversation(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->completion('ok'))]);

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        Queue::assertNotPushed(SummarizeConversation::class);
    }

    public function test_ask_rejects_another_users_conversation_with_422(): void
    {
        Http::fake();
        $owner = $this->userInTenant();
        $conversation = $this->conversationFor($owner);
        $intruder = User::factory()->forCustomer($owner->customer)->create();

        $response = $this->actingAs($intruder)->postJson('support/ask', [
            'question' => 'Hi',
            'conversation_id' => $conversation->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
        Http::assertNothingSent();
    }

    public function test_ask_rejects_a_conversation_from_another_tenant_with_422(): void
    {
        Http::fake();
        $conversation = $this->conversationFor($this->userInTenant());

        $response = $this->actingAs($this->userInTenant())->postJson('support/ask', [
            'question' => 'Hi',
            'conversation_id' => $conversation->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    public function test_ask_rejects_an_unknown_conversation_with_422(): void
    {
        Http::fake();

        $response = $this->actingAs($this->userInTenant())->postJson('support/ask', [
            'question' => 'Hi',
            'conversation_id' => 999999,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    public function test_ask_rejects_a_closed_conversation_with_422(): void
    {
        Http::fake();
        $user = $this->userInTenant();
        $conversation = Conversation::factory()->forUser($user)->closed()->create();

        $response = $this->actingAs($user)->postJson('support/ask', [
            'question' => 'Hi',
            'conversation_id' => $conversation->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    public function test_ask_rejects_a_non_integer_conversation_id_with_422(): void
    {
        Http::fake();

        $response = $this->actingAs($this->userInTenant())->postJson('support/ask', [
            'question' => 'Hi',
            'conversation_id' => 'abc',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    public function test_a_user_without_a_customer_account_can_still_hold_a_conversation(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = User::factory()->create();
        $first = $this->actingAs($user)->postJson('support/ask', ['question' => 'Hi']);

        $second = $this->actingAs($user)->postJson('support/ask', [
            'question' => 'Again',
            'conversation_id' => $first->json('conversation_id'),
        ]);

        $second->assertOk();
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column(Http::recorded()[1][0]['messages'], 'role'));
    }
}
