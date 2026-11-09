<?php

namespace Tests\Feature\Ai\Replies;

use App\Ai\Events\ReplyCompleted;
use App\Ai\Events\ReplyDelta;
use App\Ai\Events\ReplyFailed;
use App\Ai\Events\ReplyPaused;
use App\Ai\Events\ReplyRestarted;
use App\Ai\Events\ReplyToolStarted;
use App\Ai\Replies\AnswerQuestion;
use App\Ai\Replies\ResumeReply;
use App\Ai\Tools\ToolRegistry;
use App\Models\Message;
use App\Models\PendingToolCall;
use App\Models\Reply;
use App\Models\ToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\FakesStreamedModel;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ApprovalResumeTest extends TestCase
{
    use FakesStreamedModel;
    use FakesToolModel;
    use RefreshDatabase;

    private RefundTool $refunds;

    private ResponseSequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');

        $this->sequence = Http::fakeSequence();

        $this->refunds = new RefundTool;
        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)->add($this->refunds));

        Event::fake([
            ReplyDelta::class,
            ReplyToolStarted::class,
            ReplyRestarted::class,
            ReplyPaused::class,
            ReplyCompleted::class,
            ReplyFailed::class,
        ]);
    }

    public function test_approving_a_paused_reply_queues_a_resume_job_instead_of_resuming_inside_the_request(): void
    {
        [$user, $reply, $pending] = $this->pausedReply();
        Queue::fake();

        $response = $this->actingAs($user)->postJson("support/approve/{$pending->id}");

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        $response->assertJsonMissingPath('answer');
        Queue::assertPushedOn('ai', ResumeReply::class, fn (ResumeReply $job): bool => $job->replyId === $reply->id);
        $this->assertSame(Reply::STATUS_RESUMING, $reply->fresh()->status);
        $this->assertSame(1, $this->refunds->executions);
        Http::assertSentCount(1);
    }

    public function test_the_resume_job_finishes_the_run_and_completes_the_same_reply(): void
    {
        [$user, $reply, $pending] = $this->pausedReply();
        Queue::fake();
        $this->actingAs($user)->postJson("support/approve/{$pending->id}");
        $this->sequence->push($this->streamedAnswer('The refund went through.'));

        $this->runResume($reply->id);

        $reply->refresh();
        $this->assertSame(Reply::STATUS_COMPLETED, $reply->status);
        $this->assertSame('The refund went through.', $reply->content);
        $this->assertNull($reply->resume_token);
        $this->assertNull($reply->paused_for);
        Event::assertDispatched(ReplyRestarted::class);
        Event::assertDispatched(ReplyCompleted::class, fn (ReplyCompleted $event): bool => $event->replyId === $reply->id);
    }

    public function test_the_resumed_answer_is_appended_to_the_conversation(): void
    {
        [$user, $reply, $pending] = $this->pausedReply();
        Queue::fake();
        $this->actingAs($user)->postJson("support/approve/{$pending->id}");
        $this->sequence->push($this->streamedAnswer('The refund went through.'));

        $this->runResume($reply->id);

        $stored = Message::query()->withoutGlobalScopes()->orderBy('seq')->get();
        $this->assertSame(['user', 'assistant', 'assistant'], $stored->pluck('role')->all());
        $this->assertSame('The refund went through.', $stored[2]->content);
    }

    public function test_declining_resumes_with_a_declined_result_and_the_tool_never_runs(): void
    {
        [$user, $reply, $pending] = $this->pausedReply();
        Queue::fake();
        $this->actingAs($user)->postJson("support/decline/{$pending->id}")->assertOk();
        $this->sequence->push($this->streamedAnswer('Understood, no refund.'));

        $this->runResume($reply->id);

        $this->assertSame('Understood, no refund.', $reply->fresh()->content);
        $this->assertSame(0, $this->refunds->executions);
        $this->assertSame(ToolCall::DECISION_DECLINED, ToolCall::query()->latest('id')->first()->decision);
    }

    public function test_the_resume_request_streams_the_model_reply(): void
    {
        [$user, $reply, $pending] = $this->pausedReply();
        Queue::fake();
        $this->actingAs($user)->postJson("support/approve/{$pending->id}");
        $this->sequence->push($this->streamedAnswer('The refund went through.'));

        $this->runResume($reply->id);

        Http::assertSent(fn ($request): bool => $request['stream'] === true);
        Event::assertDispatched(ReplyDelta::class);
    }

    public function test_a_resume_for_a_reply_that_is_not_waiting_does_nothing(): void
    {
        $reply = Reply::factory()->forUser($this->customerUser())->completed()->create();

        $this->runResume($reply->id);

        Http::assertNothingSent();
        Event::assertNotDispatched(ReplyRestarted::class);
    }

    public function test_the_resume_job_fails_the_reply_when_it_runs_out_of_attempts(): void
    {
        [$user, $reply] = $this->pausedReply();
        $reply->update(['status' => Reply::STATUS_RESUMING]);

        (new ResumeReply($reply->id))->failed(new RuntimeException('model offline'));

        $this->assertSame(Reply::STATUS_FAILED, $reply->fresh()->status);
        Event::assertDispatched(ReplyFailed::class);
    }

    public function test_a_second_user_cannot_approve_the_pending_call(): void
    {
        [, $reply, $pending] = $this->pausedReply();
        Queue::fake();

        $this->actingAs($this->customerUser())->postJson("support/approve/{$pending->id}")->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(Reply::STATUS_PAUSED, $reply->fresh()->status);
    }

    public function test_an_approval_for_a_run_that_was_not_queued_still_resumes_inline(): void
    {
        $user = $this->customerUser();
        config()->set('ai.replies.enabled', false);
        $this->sequence
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]))
            ->push($this->answerResponse('The refund went through.'));
        $this->actingAs($user)->postJson('support/ask', ['question' => 'Refund my late fee'])->assertOk();
        $pending = PendingToolCall::query()->sole();

        $response = $this->actingAs($user)->postJson("support/approve/{$pending->id}");

        $response->assertOk()->assertJsonPath('answer', 'The refund went through.');
    }

    private function runResume(string $replyId): void
    {
        app()->call([new ResumeReply($replyId), 'handle']);
    }

    private function pausedReply(): array
    {
        $user = $this->customerUser();
        $reply = Reply::factory()->forUser($user)->create();
        $this->sequence->push($this->streamedToolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6]));

        AnswerQuestion::dispatchSync($reply->id);

        return [$user, $reply->fresh(), PendingToolCall::query()->sole()];
    }
}
