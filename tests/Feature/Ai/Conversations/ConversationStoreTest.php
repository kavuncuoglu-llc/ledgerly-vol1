<?php

namespace Tests\Feature\Ai\Conversations;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ConversationStoreTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    private ConversationStore $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ConversationStore::class);

        $this->user = $this->userInTenant();

        $this->actingAs($this->user);
    }

    public function test_start_creates_an_open_conversation_for_the_user_in_the_users_tenant(): void
    {
        $user = $this->user;

        $conversation = $this->store->start($user, SupportPrompt::VERSION);

        $this->assertTrue($conversation->isOpen());
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertSame($user->customer_id, $conversation->tenant_id);
        $this->assertSame(SupportPrompt::VERSION, $conversation->prompt_version);
        $this->assertSame(0, $conversation->summary_through_seq);
    }

    public function test_append_assigns_increasing_sequence_numbers(): void
    {
        $user = $this->user;
        $conversation = $this->store->start($user, SupportPrompt::VERSION);

        $first = $this->store->append($conversation, ['role' => 'user', 'content' => 'Hello']);
        $second = $this->store->append($conversation, ['role' => 'assistant', 'content' => 'Hi']);

        $this->assertSame(1, $first->seq);
        $this->assertSame(2, $second->seq);
    }

    public function test_append_counts_tokens_when_the_message_is_written(): void
    {
        $user = $this->user;
        $conversation = $this->store->start($user, SupportPrompt::VERSION);

        $message = $this->store->append($conversation, ['role' => 'user', 'content' => str_repeat('a', 36)]);

        $this->assertSame(10 + (int) config('ai.conversation.message_overhead_tokens'), $message->tokens);
    }

    public function test_append_counts_the_tool_calls_of_an_assistant_turn(): void
    {
        $user = $this->user;
        $conversation = $this->store->start($user, SupportPrompt::VERSION);

        $message = $this->store->append($conversation, [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [$this->toolCall('call_8a1', 'list_invoices')],
        ]);

        $this->assertGreaterThan((int) config('ai.conversation.message_overhead_tokens'), $message->tokens);
    }

    public function test_append_stores_tool_call_fields_verbatim_and_replays_them_in_the_same_shape(): void
    {
        $user = $this->user;
        $conversation = $this->store->start($user, SupportPrompt::VERSION);
        $call = $this->toolCall('call_8a1', 'list_invoices');
        $this->store->append($conversation, ['role' => 'user', 'content' => 'List them']);
        $this->store->append($conversation, ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]]);
        $this->store->append($conversation, ['role' => 'tool', 'tool_call_id' => 'call_8a1', 'content' => 'rows']);
        $this->store->append($conversation, ['role' => 'assistant', 'content' => 'Done']);

        $history = $this->store->history($conversation->fresh(), 4000);

        $this->assertSame([$call], $history[1]['tool_calls']);
        $this->assertNull($history[1]['content']);
        $this->assertSame('call_8a1', $history[2]['tool_call_id']);
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], array_column($history, 'role'));
    }

    public function test_append_records_the_model_call_of_an_assistant_turn(): void
    {
        $user = $this->user;
        $conversation = $this->store->start($user, SupportPrompt::VERSION);

        $message = $this->store->append($conversation, ['role' => 'assistant', 'content' => 'Hi', 'model_call_id' => 77]);

        $this->assertSame(77, $message->model_call_id);
    }

    public function test_history_returns_every_exchange_when_the_allowance_is_large(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'one', 'reply one');
        $this->addExchange($conversation, 'two', 'reply two');

        $history = $this->store->history($conversation, 1000);

        $this->assertSame(['one', 'reply one', 'two', 'reply two'], array_column($history, 'content'));
    }

    public function test_history_drops_the_oldest_whole_exchanges_that_do_not_fit(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'first', 'r1');
        $this->addExchange($conversation, 'second', 'r2');
        $this->addExchange($conversation, 'third', 'r3');
        $this->addExchange($conversation, 'fourth', 'r4');

        $history = $this->store->window($conversation, 40, pinFirst: false);

        $this->assertSame(['third', 'r3', 'fourth', 'r4'], array_column($history, 'content'));
    }

    public function test_history_pins_the_first_exchange_and_keeps_the_newest_that_fit(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'first', 'r1');
        $this->addExchange($conversation, 'second', 'r2');
        $this->addExchange($conversation, 'third', 'r3');
        $this->addExchange($conversation, 'fourth', 'r4');

        $history = $this->store->window($conversation, 40);

        $this->assertSame(['first', 'r1', 'fourth', 'r4'], array_column($history, 'content'));
    }

    public function test_history_trims_by_stored_tokens_not_by_message_count(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'small one', 'r1', tokensEach: 5);
        $this->addExchange($conversation, 'big', 'huge reply', tokensEach: 200);
        $this->addExchange($conversation, 'small two', 'r3', tokensEach: 5);

        $history = $this->store->window($conversation, 50, pinFirst: false);

        $this->assertSame(['small two', 'r3'], array_column($history, 'content'));
    }

    public function test_history_never_returns_a_tool_result_without_its_request(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list please', 'list_invoices', 'rows', 'here you go', toolTokens: 100);
        $this->addExchange($conversation, 'thanks', 'welcome');

        $history = $this->store->window($conversation, 30, pinFirst: false);

        $this->assertSame(['thanks', 'welcome'], array_column($history, 'content'));
    }

    public function test_history_skips_a_pinned_first_exchange_that_alone_exceeds_the_allowance(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'long opening', 'long reply', tokensEach: 500);
        $this->addExchange($conversation, 'next', 'reply');

        $history = $this->store->window($conversation, 100);

        $this->assertSame(['next', 'reply'], array_column($history, 'content'));
    }

    public function test_history_reserves_room_for_the_summary_and_excludes_the_summarized_turns(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'old question', 'old reply');
        $this->addExchange($conversation, 'new question', 'new reply');
        $conversation->update([
            'summary' => 'Problem: INV-4471',
            'summary_through_seq' => 2,
        ]);

        $history = $this->store->history($conversation->fresh(), 1000);

        $this->assertSame(['new question', 'new reply'], array_column($history, 'content'));
    }

    public function test_history_does_not_pin_the_first_exchange_once_it_is_covered_by_a_summary(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'covered', 'covered reply');
        $this->addExchange($conversation, 'second', 'r2');
        $this->addExchange($conversation, 'third', 'r3');
        $this->addExchange($conversation, 'fourth', 'r4');
        $conversation->update([
            'summary' => 'S',
            'summary_through_seq' => 2,
        ]);

        $history = $this->store->history($conversation->fresh(), 60);

        $this->assertSame(['third', 'r3', 'fourth', 'r4'], array_column($history, 'content'));
    }

    public function test_cutoff_seq_leaves_the_most_recent_exchanges_alone(): void
    {
        $conversation = $this->conversationFor($this->user);

        foreach (range(1, 6) as $number) {
            $this->addExchange($conversation, "q{$number}", "a{$number}");
        }

        $cutoff = $this->store->cutoffSeq($conversation, 4);

        $this->assertSame(4, $cutoff);
    }

    public function test_cutoff_seq_stays_at_the_summary_point_when_there_is_nothing_to_summarize(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'q1', 'a1');
        $this->addExchange($conversation, 'q2', 'a2');

        $cutoff = $this->store->cutoffSeq($conversation, 4);

        $this->assertSame(0, $cutoff);
    }

    public function test_exchanges_between_returns_whole_exchanges_inside_the_range(): void
    {
        $conversation = $this->conversationFor($this->user);

        foreach (range(1, 4) as $number) {
            $this->addExchange($conversation, "q{$number}", "a{$number}");
        }

        $exchanges = $this->store->exchangesBetween($conversation, 2, 6);

        $this->assertCount(2, $exchanges);
        $this->assertSame(3, $exchanges->first()->firstSeq());
        $this->assertSame(6, $exchanges->last()->lastSeq());
    }

    public function test_needs_summary_is_true_only_when_unsummarized_tokens_exceed_the_allowance(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'q', 'a', tokensEach: 50);

        $this->assertFalse($this->store->needsSummary($conversation, 100));
        $this->assertTrue($this->store->needsSummary($conversation, 99));
    }

    public function test_needs_summary_ignores_tokens_already_covered_by_the_summary(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'q', 'a', tokensEach: 500);
        $conversation->update(['summary_through_seq' => 2]);

        $this->assertFalse($this->store->needsSummary($conversation->fresh(), 100));
    }

    public function test_needs_summary_honors_a_configured_trigger(): void
    {
        config()->set('ai.conversation.summary_trigger_tokens', 1000000);
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'q', 'a', tokensEach: 5000);

        $this->assertFalse($this->store->needsSummary($conversation, 100));
    }

    public function test_compact_replaces_an_old_read_result_with_a_stub_and_shrinks_its_token_count(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list', 'list_invoices', $this->invoiceTable(), 'You have two overdue.');
        $this->addExchange($conversation, 'next', 'ok');
        $this->addExchange($conversation, 'again', 'ok');

        $compacted = $this->store->compact($conversation);

        $tool = Message::query()->where('role', 'tool')->sole();
        $stub = json_decode($tool->content, true);
        $this->assertSame(1, $compacted);
        $this->assertTrue($stub['compacted']);
        $this->assertSame('list_invoices', $stub['tool']);
        $this->assertSame('3 invoices listed; 2 overdue: INV-4471, INV-4438', $stub['summary']);
        $this->assertLessThan(300, $tool->tokens);
        $this->assertSame('call_1', $tool->tool_call_id);
    }

    public function test_compact_leaves_results_inside_the_delay_window_verbatim(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list', 'list_invoices', $this->invoiceTable(), 'You have two overdue.');
        $this->addExchange($conversation, 'next', 'ok');

        $compacted = $this->store->compact($conversation);

        $this->assertSame(0, $compacted);
        $this->assertStringContainsString('INV-4471', Message::query()->where('role', 'tool')->sole()->content);
    }

    public function test_compact_with_a_zero_delay_compacts_the_latest_exchange_too(): void
    {
        config()->set('ai.conversation.compact_after_exchanges', 0);
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list', 'list_invoices', $this->invoiceTable(), 'You have two overdue.');

        $compacted = $this->store->compact($conversation);

        $this->assertSame(1, $compacted);
    }

    public function test_compact_keeps_errors_denials_and_unknown_tools_verbatim(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'a', 'lookup_invoice', '{"ok":false,"error":"not permitted"}', 'r', callId: 'call_a');
        $this->addToolExchange($conversation, 'b', 'mystery_tool', str_repeat('x', 900), 'r', callId: 'call_b');
        $this->addExchange($conversation, 'c', 'r');
        $this->addExchange($conversation, 'd', 'r');

        $compacted = $this->store->compact($conversation);

        $this->assertSame(0, $compacted);
    }

    public function test_compact_is_idempotent_and_needs_compaction_goes_false(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list', 'list_invoices', $this->invoiceTable(), 'x');
        $this->addExchange($conversation, 'next', 'ok');
        $this->addExchange($conversation, 'again', 'ok');

        $this->assertTrue($this->store->needsCompaction($conversation));
        $this->store->compact($conversation);

        $this->assertFalse($this->store->needsCompaction($conversation));
        $this->assertSame(0, $this->store->compact($conversation));
    }

    public function test_compacted_stub_is_what_history_emits_for_that_message(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addToolExchange($conversation, 'list', 'list_invoices', $this->invoiceTable(), 'x');
        $this->addExchange($conversation, 'next', 'ok');
        $this->addExchange($conversation, 'again', 'ok');
        $this->store->compact($conversation);

        $history = $this->store->history($conversation->fresh(), 4000);

        $toolTurn = collect($history)->firstWhere('role', 'tool');
        $this->assertStringStartsWith('{"compacted":true', $toolTurn['content']);
        $this->assertSame('call_1', $toolTurn['tool_call_id']);
    }

    public function test_close_marks_the_conversation_closed(): void
    {
        $conversation = $this->conversationFor($this->user);

        $this->store->close($conversation);

        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
    }

    public function test_history_is_empty_for_a_conversation_outside_the_current_tenant(): void
    {
        $conversation = $this->conversationFor($this->user);
        $this->addExchange($conversation, 'private', 'private');
        $other = $this->userInTenant();

        $history = app(CurrentTenant::class)->runAs($other->customer_id, fn () => $this->store->history($conversation, 1000));

        $this->assertSame([], $history);
    }

    private function toolCall(string $id, string $name): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => '{"status":null,"months":6}',
            ],
        ];
    }

    private function invoiceTable(): string
    {
        return "Amounts in USD. Today is 2026-04-20.\ninvoices[3]{number,status,due,total,days_overdue}:\n INV-4471,overdue,2026-04-02,1284.50,18\n INV-4438,overdue,2026-03-10,300.00,41\n INV-4402,paid,2026-02-10,90.00,0";
    }
}
