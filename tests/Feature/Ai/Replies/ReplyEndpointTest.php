<?php

namespace Tests\Feature\Ai\Replies;

use App\Ai\Streaming\ReplyBuffer;
use App\Models\PendingToolCall;
use App\Models\Reply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ReplyEndpointTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
    }

    public function test_show_returns_a_finished_reply_with_its_stored_content_and_sources(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed('Seven days.')->create([
            'sources' => [['label' => 'late-fees › Intro', 'url' => '/help/billing/late-fees']],
            'citations_ok' => false,
            'feedback' => 'up',
        ]);

        $response = $this->actingAs($user)->getJson("replies/{$reply->id}");

        $response->assertOk()->assertExactJson(['data' => [
            'id' => $reply->id,
            'conversation_id' => $reply->conversation_id,
            'channel' => "reply.{$reply->id}",
            'question' => 'Why was I charged a late fee?',
            'status' => 'completed',
            'content' => 'Seven days.',
            'chunks' => [],
            'sources' => [['label' => 'late-fees › Intro', 'url' => '/help/billing/late-fees']],
            'citations_ok' => false,
            'paused_for' => null,
            'approvals' => [],
            'feedback' => 'up',
        ]]);
    }

    public function test_show_returns_the_partial_text_and_chunks_of_a_reply_still_streaming(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->create(['status' => Reply::STATUS_STREAMING]);
        app(ReplyBuffer::class)->append($reply->id, 'Late fees ');
        app(ReplyBuffer::class)->append($reply->id, 'apply after ');

        $response = $this->actingAs($user)->getJson("replies/{$reply->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'streaming')
            ->assertJsonPath('data.content', 'Late fees apply after ')
            ->assertJsonPath('data.chunks', ['Late fees ', 'apply after ']);
    }

    public function test_show_lists_the_approvals_a_paused_reply_is_waiting_for(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->paused('run-1', 'Refund $21.60')->create(['resume_token' => '6f1b8c40-2c36-4a43-9e29-6e5b2b6f6a10']);
        $pending = PendingToolCall::create([
            'user_id' => $user->id,
            'run_id' => '6f1b8c40-2c36-4a43-9e29-6e5b2b6f6a10',
            'tool' => 'issue_refund',
            'tool_call_id' => 'call_1',
            'arguments' => [],
            'summary' => 'Refund $21.60',
            'status' => PendingToolCall::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->getJson("replies/{$reply->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.paused_for', 'Refund $21.60')
            ->assertJsonPath('data.approvals.0.id', $pending->id);
    }

    public function test_show_requires_authentication(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create();

        $this->getJson("replies/{$reply->id}")->assertUnauthorized();
    }

    public function test_show_forbids_a_reply_that_belongs_to_another_user_in_the_same_tenant(): void
    {
        $owner = $this->userInTenant();
        $neighbour = $this->userInTenant($owner->customer);
        $reply = Reply::factory()->forUser($owner)->create();

        $this->actingAs($neighbour)->getJson("replies/{$reply->id}")->assertForbidden();
    }

    public function test_show_hides_a_reply_from_another_tenant_with_a_404(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create();

        $this->actingAs($this->userInTenant())->getJson("replies/{$reply->id}")->assertNotFound();
    }

    public function test_show_returns_404_for_an_unknown_reply(): void
    {
        $this->actingAs($this->userInTenant())
            ->getJson('replies/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_feedback_is_stored_on_a_completed_reply(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed()->create();

        $response = $this->actingAs($user)->postJson("replies/{$reply->id}/feedback", ['feedback' => 'down']);

        $response->assertOk()->assertJsonPath('data.feedback', 'down');
        $this->assertSame('down', $reply->fresh()->feedback);
    }

    public function test_feedback_can_be_changed_by_sending_it_again(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed()->create(['feedback' => 'down']);

        $this->actingAs($user)->postJson("replies/{$reply->id}/feedback", ['feedback' => 'up'])->assertOk();

        $this->assertSame('up', $reply->fresh()->feedback);
    }

    public function test_feedback_rejects_a_value_other_than_up_or_down(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed()->create();

        $this->actingAs($user)
            ->postJson("replies/{$reply->id}/feedback", ['feedback' => 'meh'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['feedback']);
    }

    public function test_feedback_on_a_reply_that_is_not_finished_is_a_conflict(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->create();

        $this->actingAs($user)->postJson("replies/{$reply->id}/feedback", ['feedback' => 'up'])->assertConflict();
    }

    public function test_feedback_is_forbidden_for_another_user(): void
    {
        $owner = $this->userInTenant();
        $reply = Reply::factory()->forUser($owner)->completed()->create();

        $this->actingAs($this->userInTenant($owner->customer))
            ->postJson("replies/{$reply->id}/feedback", ['feedback' => 'up'])
            ->assertForbidden();
    }

    public function test_feedback_requires_authentication(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->completed()->create();

        $this->postJson("replies/{$reply->id}/feedback", ['feedback' => 'up'])->assertUnauthorized();
    }
}
