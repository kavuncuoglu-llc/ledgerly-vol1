<?php

namespace Tests\Feature\Ai\Conversations;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\SummarizationFailed;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class SummarizeConversationTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->user = $this->userInTenant();
    }

    public function test_handle_summarizes_all_but_the_four_most_recent_exchanges(): void
    {
        Http::fake(['*' => Http::response($this->completion('Problem: INV-4471 disputed.'))]);
        $conversation = $this->longConversation(6);

        SummarizeConversation::dispatchSync($conversation, 100);

        $fresh = $conversation->fresh();
        $this->assertSame('Problem: INV-4471 disputed.', $fresh->summary);
        $this->assertSame(4, $fresh->summary_through_seq);
    }

    public function test_handle_asks_the_provider_for_a_bigger_window_when_one_is_configured(): void
    {
        config()->set('ai.conversation.summary_context_window', 16384);
        Http::fake(['*' => Http::response(['model' => 'llama3.1:8b', 'message' => ['role' => 'assistant', 'content' => 'S'], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 120, 'eval_count' => 40])]);

        SummarizeConversation::dispatchSync($this->longConversation(6), 100);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat') && $request['options']['num_ctx'] === 16384);
    }

    public function test_handle_leaves_the_providers_window_alone_by_default(): void
    {
        Http::fake(['*' => Http::response($this->completion('S'))]);

        SummarizeConversation::dispatchSync($this->longConversation(6), 100);

        Http::assertSent(fn (Request $request): bool => ! isset($request['num_ctx']));
    }

    public function test_handle_sends_the_summarize_prompt_with_identifiers_and_the_turns_to_delete(): void
    {
        Http::fake(['*' => Http::response($this->completion('S'))]);
        $conversation = $this->longConversation(6);

        SummarizeConversation::dispatchSync($conversation, 100);

        Http::assertSent(function (Request $request): bool {
            $system = $request['messages'][0]['content'];
            $turns = $request['messages'][1]['content'];

            return str_contains($system, 'The turns you are given will be deleted')
                && str_contains($system, 'invoice numbers')
                && str_contains($turns, 'user: question 1')
                && str_contains($turns, 'assistant: answer 2')
                && ! str_contains($turns, 'question 3')
                && $request['temperature'] === 0
                && $request['max_tokens'] === 400;
        });
    }

    public function test_handle_includes_the_existing_summary_in_the_next_update(): void
    {
        Http::fake(['*' => Http::response($this->completion('Updated'))]);
        $conversation = $this->longConversation(8);
        $conversation->update([
            'summary' => 'Earlier: ticket T-10422',
            'summary_through_seq' => 4,
        ]);

        SummarizeConversation::dispatchSync($conversation->fresh(), 100);

        Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][1]['content'], 'Earlier: ticket T-10422')
            && str_contains($request['messages'][1]['content'], 'question 3')
            && ! str_contains($request['messages'][1]['content'], 'question 1'));
        $this->assertSame(8, $conversation->fresh()->summary_through_seq);
    }

    public function test_handle_logs_the_call_in_model_calls_against_the_conversation(): void
    {
        Http::fake(['*' => Http::response($this->completion('S'))]);
        $conversation = $this->longConversation(6);

        SummarizeConversation::dispatchSync($conversation, 100);

        $call = ModelCall::query()->sole();
        $this->assertSame('conversation.summarize', $call->feature);
        $this->assertSame($conversation->id, $call->conversation_id);
    }

    public function test_handle_does_nothing_when_the_allowance_is_not_exceeded(): void
    {
        Http::fake();
        $conversation = $this->longConversation(6);

        SummarizeConversation::dispatchSync($conversation, 100000);

        Http::assertNothingSent();
        $this->assertNull($conversation->fresh()->summary);
    }

    public function test_handle_does_nothing_when_there_are_only_recent_exchanges(): void
    {
        Http::fake();
        $conversation = $this->longConversation(3);

        SummarizeConversation::dispatchSync($conversation, 1);

        Http::assertNothingSent();
    }

    public function test_handle_keeps_the_old_summary_when_the_model_call_fails(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $conversation = $this->longConversation(6);
        $conversation->update(['summary' => 'Old summary']);

        $this->expectException(ModelRequestFailed::class);

        try {
            SummarizeConversation::dispatchSync($conversation->fresh(), 100);
        } finally {
            $fresh = $conversation->fresh();
            $this->assertSame('Old summary', $fresh->summary);
            $this->assertSame(0, $fresh->summary_through_seq);
        }
    }

    public function test_handle_rejects_an_empty_summary_and_keeps_the_old_one(): void
    {
        Http::fake(['*' => Http::response($this->completion('   '))]);
        $conversation = $this->longConversation(6);
        $conversation->update(['summary' => 'Old summary']);

        $this->expectException(SummarizationFailed::class);

        try {
            SummarizeConversation::dispatchSync($conversation->fresh(), 100);
        } finally {
            $this->assertSame('Old summary', $conversation->fresh()->summary);
        }
    }

    public function test_handle_compacts_old_tool_results_in_the_same_run(): void
    {
        Http::fake(['*' => Http::response($this->completion('S'))]);
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list', 'list_invoices', "invoices[1]{number,status}:\n INV-4471,overdue", 'x', toolTokens: 500);
        $this->addExchange($conversation, 'next', 'ok');
        $this->addExchange($conversation, 'again', 'ok');

        SummarizeConversation::dispatchSync($conversation, 100000);

        $tool = Message::query()->withoutGlobalScopes()->where('role', 'tool')->sole();
        $this->assertStringStartsWith('{"compacted":true', $tool->content);
    }

    public function test_handle_runs_without_a_logged_in_user_using_the_conversations_tenant(): void
    {
        Http::fake(['*' => Http::response($this->completion('S'))]);
        $conversation = $this->longConversation(6);
        auth()->forgetUser();

        SummarizeConversation::dispatchSync($conversation, 100);

        $this->assertSame('S', $conversation->fresh()->summary);
    }

    public function test_job_survives_queue_serialization(): void
    {
        $conversation = $this->longConversation(2);
        $job = new SummarizeConversation($conversation, 100);

        $restored = unserialize(serialize($job));

        $this->assertSame($conversation->id, $restored->conversation->id);
        $this->assertSame(100, $restored->allowance);
    }

    public function test_store_summarize_runs_the_job_immediately(): void
    {
        Http::fake(['*' => Http::response($this->completion('Forced'))]);
        $conversation = $this->longConversation(6);

        app(ConversationStore::class)->summarize($conversation);

        $this->assertSame('Forced', $conversation->fresh()->summary);
    }

    private function longConversation(int $exchanges): Conversation
    {
        $this->actingAs($this->user);

        $conversation = $this->conversationFor($this->user);

        foreach (range(1, $exchanges) as $number) {
            $this->addExchange($conversation, "question {$number}", "answer {$number}", tokensEach: 50);
        }

        return $conversation;
    }
}
