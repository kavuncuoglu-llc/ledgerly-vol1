<?php

namespace Tests\Feature\Support;

use App\Ai\Tools\ToolRegistry;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ConversationApprovalTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');

        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)->add(new RefundTool));
    }

    public function test_a_paused_run_stores_a_plain_note_instead_of_an_unanswered_tool_call(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]));

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Refund my late fee'])->assertOk();

        $stored = Message::query()->withoutGlobalScopes()->orderBy('seq')->get();
        $this->assertSame(['user', 'assistant'], $stored->pluck('role')->all());
        $this->assertSame('Waiting for the customer to approve: Refund $21.6 late fee on invoice 4471', $stored[1]->content);
        $this->assertNull($stored[1]->tool_calls);
    }

    public function test_approving_appends_the_resumed_answer_to_the_same_conversation(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]))
            ->push($this->answerResponse('The refund went through.'));
        $this->actingAs($user)->postJson('support/ask', ['question' => 'Refund my late fee'])->assertOk();
        $pending = PendingToolCall::query()->sole();

        $this->actingAs($user)->postJson("support/approve/{$pending->id}")->assertOk();

        $stored = Message::query()->withoutGlobalScopes()->orderBy('seq')->get();
        $this->assertSame(['user', 'assistant', 'assistant'], $stored->pluck('role')->all());
        $this->assertSame('The refund went through.', $stored[2]->content);
        $this->assertSame(Conversation::query()->sole()->id, ModelCall::query()->latest('id')->first()->conversation_id);
    }
}
