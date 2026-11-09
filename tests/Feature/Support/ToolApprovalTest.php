<?php

namespace Tests\Feature\Support;

use App\Ai\Tools\ToolRegistry;
use App\Models\PendingToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ToolApprovalTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    private RefundTool $refund;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');

        $this->refund = new RefundTool;
        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)->add($this->refund));
    }

    public function test_ask_returns_the_pending_approval_instead_of_running_a_high_risk_tool(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]));

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Refund my late fee']);

        $response->assertOk()->assertJsonPath('pending.0.summary', 'Refund $21.6 late fee on invoice 4471');
        $this->assertSame(0, $this->refund->executions);
        $this->assertStringContainsString('/support/approvals/'.PendingToolCall::query()->sole()->id, $response->json('pending.0.approval_url'));
    }

    public function test_show_returns_the_pending_call_to_its_owner(): void
    {
        $user = $this->customerUser();
        $this->askForRefund($user);
        $pending = PendingToolCall::query()->sole();

        $response = $this->actingAs($user)->getJson("support/approvals/{$pending->id}");

        $response->assertOk()->assertJson(['data' => ['id' => $pending->id, 'tool' => 'issue_refund', 'status' => 'pending']]);
    }

    public function test_approve_runs_the_tool_and_returns_the_resumed_answer(): void
    {
        $user = $this->customerUser();
        $this->askForRefund($user, 'The refund went through.');
        $pending = PendingToolCall::query()->sole();

        $response = $this->actingAs($user)->postJson("support/approve/{$pending->id}");

        $response->assertOk()->assertJson([
            'data' => ['status' => 'approved'],
            'answer' => 'The refund went through.',
        ]);
        $this->assertSame(1, $this->refund->executions);
    }

    public function test_decline_does_not_run_the_tool_and_returns_the_resumed_answer(): void
    {
        $user = $this->customerUser();
        $this->askForRefund($user, 'I will not refund it.');
        $pending = PendingToolCall::query()->sole();

        $response = $this->actingAs($user)->postJson("support/decline/{$pending->id}");

        $response->assertOk()->assertJson([
            'data' => ['status' => 'declined'],
            'answer' => 'I will not refund it.',
        ]);
        $this->assertSame(0, $this->refund->executions);
    }

    public function test_approve_answers_409_when_the_call_was_already_resolved(): void
    {
        $user = $this->customerUser();
        $this->askForRefund($user, 'Done.');
        $pending = PendingToolCall::query()->sole();
        $this->actingAs($user)->postJson("support/approve/{$pending->id}")->assertOk();

        $response = $this->actingAs($user)->postJson("support/approve/{$pending->id}");

        $response->assertStatus(409);
        $this->assertSame(1, $this->refund->executions);
    }

    public function test_another_user_cannot_see_approve_or_decline_the_call(): void
    {
        $owner = $this->customerUser();
        $this->askForRefund($owner);
        $pending = PendingToolCall::query()->sole();
        $stranger = $this->customerUser();

        $this->actingAs($stranger)->getJson("support/approvals/{$pending->id}")->assertForbidden();
        $this->actingAs($stranger)->postJson("support/approve/{$pending->id}")->assertForbidden();
        $this->actingAs($stranger)->postJson("support/decline/{$pending->id}")->assertForbidden();
        $this->assertSame(0, $this->refund->executions);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_guests_get_401_on_every_approval_endpoint(): void
    {
        $id = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $this->getJson("support/approvals/{$id}")->assertUnauthorized();
        $this->postJson("support/approve/{$id}")->assertUnauthorized();
        $this->postJson("support/decline/{$id}")->assertUnauthorized();
    }

    public function test_unknown_or_malformed_ids_answer_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('support/approvals/0f8fad5b-d9cb-469f-a165-70867728950e')->assertNotFound();
        $this->actingAs($user)->postJson('support/approve/not-a-uuid')->assertNotFound();
        $this->actingAs($user)->postJson('support/decline/0f8fad5b-d9cb-469f-a165-70867728950e')->assertNotFound();
    }

    private function askForRefund(User $user, string $afterwards = 'Done.'): void
    {
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]))
            ->push($this->answerResponse($afterwards));

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Refund my late fee'])->assertOk();
    }
}
