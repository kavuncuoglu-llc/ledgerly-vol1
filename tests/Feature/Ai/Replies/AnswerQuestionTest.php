<?php

namespace Tests\Feature\Ai\Replies;

use App\Ai\Events\ReplyCompleted;
use App\Ai\Events\ReplyDelta;
use App\Ai\Events\ReplyFailed;
use App\Ai\Events\ReplyPaused;
use App\Ai\Events\ReplyRestarted;
use App\Ai\Events\ReplyToolStarted;
use App\Ai\Replies\AnswerQuestion;
use App\Ai\Streaming\ReplyBuffer;
use App\Ai\Tools\ToolRegistry;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\Reply;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use RuntimeException;
use Tests\Support\FakesStreamedModel;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class AnswerQuestionTest extends TestCase
{
    use FakesStreamedModel;
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        Event::fake([
            ReplyDelta::class,
            ReplyToolStarted::class,
            ReplyRestarted::class,
            ReplyPaused::class,
            ReplyCompleted::class,
            ReplyFailed::class,
        ]);
    }

    public function test_handle_runs_the_assistant_and_completes_the_placeholder(): void
    {
        Http::fakeSequence()->push($this->streamedAnswer('Seven days.'));
        $reply = $this->pendingReply();

        AnswerQuestion::dispatchSync($reply->id);

        $reply->refresh();
        $this->assertSame(Reply::STATUS_COMPLETED, $reply->status);
        $this->assertSame('Seven days.', $reply->content);
        $this->assertSame([], $reply->sources);
        $this->assertTrue($reply->citations_ok);
    }

    public function test_handle_announces_a_tool_call_with_a_friendly_label_before_the_answer_streams(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->streamedToolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471']))
            ->push($this->streamedAnswer('It is 18 days overdue.'));
        $reply = $this->pendingReply($user);

        AnswerQuestion::dispatchSync($reply->id);

        Event::assertDispatched(ReplyToolStarted::class, fn (ReplyToolStarted $event): bool => $event->replyId === $reply->id
            && $event->tool === 'lookup_invoice'
            && $event->label === 'Looking up your invoice...');
        $this->assertSame('It is 18 days overdue.', $reply->fresh()->content);
        $this->assertSame(2, ModelCall::query()->count());
    }

    public function test_an_unlabelled_tool_gets_the_default_status_text(): void
    {
        Http::fakeSequence()
            ->push($this->streamedToolCall('call_1', 'mystery_tool', []))
            ->push($this->streamedAnswer('Done.'));
        $reply = $this->pendingReply();

        AnswerQuestion::dispatchSync($reply->id);

        Event::assertDispatched(ReplyToolStarted::class, fn (ReplyToolStarted $event): bool => $event->label === 'Working on it...');
    }

    public function test_handle_pauses_the_reply_when_a_tool_needs_approval(): void
    {
        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)->add(new RefundTool));
        Http::fakeSequence()->push($this->streamedToolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6]));
        $reply = $this->pendingReply();

        AnswerQuestion::dispatchSync($reply->id);

        $pending = PendingToolCall::query()->sole();
        $reply->refresh();
        $this->assertSame(Reply::STATUS_PAUSED, $reply->status);
        $this->assertSame($pending->run_id, $reply->resume_token);
        $this->assertSame('Refund $21.6 late fee on invoice 4471', $reply->paused_for);
        $this->assertNotNull($reply->paused_at);
        Event::assertDispatched(ReplyPaused::class, fn (ReplyPaused $event): bool => $event->replyId === $reply->id
            && $event->summary === 'Refund $21.6 late fee on invoice 4471'
            && $event->approvals[0]['id'] === $pending->id
            && str_ends_with($event->approvals[0]['approve_url'], "support/approve/{$pending->id}")
            && str_ends_with($event->approvals[0]['decline_url'], "support/decline/{$pending->id}"));
        Event::assertNotDispatched(ReplyCompleted::class);
    }

    public function test_a_retry_after_success_does_nothing(): void
    {
        Http::fake();
        $reply = Reply::factory()->forUser($this->customerUser())->completed('Already done.')->create();

        AnswerQuestion::dispatchSync($reply->id);

        Http::assertNothingSent();
        $this->assertSame('Already done.', $reply->fresh()->content);
    }

    public function test_a_missing_reply_does_nothing(): void
    {
        Http::fake();

        AnswerQuestion::dispatchSync('00000000-0000-0000-0000-000000000000');

        Http::assertNothingSent();
    }

    public function test_a_second_attempt_clears_the_buffer_and_tells_the_browser_to_start_over(): void
    {
        Http::fakeSequence()->push($this->streamedAnswer('Seven days.'));
        $reply = $this->pendingReply();
        app(ReplyBuffer::class)->append($reply->id, 'Half an ans');
        $job = new AnswerQuestion($reply->id);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        Event::assertDispatched(ReplyRestarted::class, fn (ReplyRestarted $event): bool => $event->replyId === $reply->id);
        $this->assertSame(['Seven days.'], app(ReplyBuffer::class)->chunks($reply->id));
    }

    public function test_the_first_attempt_does_not_send_a_restart(): void
    {
        Http::fakeSequence()->push($this->streamedAnswer('Seven days.'));
        $reply = $this->pendingReply();

        AnswerQuestion::dispatchSync($reply->id);

        Event::assertNotDispatched(ReplyRestarted::class);
    }

    public function test_failed_marks_the_reply_failed_with_a_readable_message_and_broadcasts_it(): void
    {
        $reply = $this->pendingReply();
        $reply->update(['status' => Reply::STATUS_STREAMING]);

        (new AnswerQuestion($reply->id))->failed(new RuntimeException('Connection refused'));

        $reply->refresh();
        $this->assertSame(Reply::STATUS_FAILED, $reply->status);
        $this->assertSame('Sorry, something went wrong. Our team has been notified.', $reply->content);
        $this->assertSame('Connection refused', $reply->error);
        Event::assertDispatched(ReplyFailed::class, fn (ReplyFailed $event): bool => $event->replyId === $reply->id
            && $event->message === 'Sorry, something went wrong. Our team has been notified.');
    }

    public function test_failed_records_the_failure_against_the_feature_in_model_calls(): void
    {
        $reply = $this->pendingReply();

        (new AnswerQuestion($reply->id))->failed(new RuntimeException('boom'));

        $call = ModelCall::query()->sole();
        $this->assertSame('support.reply', $call->feature);
        $this->assertSame('job_failed', $call->finish_reason);
        $this->assertSame($reply->conversation_id, $call->conversation_id);
    }

    public function test_failed_for_a_reply_that_no_longer_exists_does_not_throw(): void
    {
        (new AnswerQuestion('00000000-0000-0000-0000-000000000000'))->failed(new RuntimeException('boom'));

        Event::assertNotDispatched(ReplyFailed::class);
    }

    public function test_the_job_is_unique_per_reply_and_carries_the_documented_limits(): void
    {
        $job = new AnswerQuestion('abc');

        $this->assertSame('abc', $job->uniqueId());
        $this->assertSame('ai', $job->queue);
        $this->assertSame(180, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 15, 60], $job->backoff);
    }

    public function test_a_conversation_in_another_tenant_is_answered_under_its_own_tenant(): void
    {
        Http::fakeSequence()->push($this->streamedAnswer('Done.'));
        $reply = $this->pendingReply();

        AnswerQuestion::dispatchSync($reply->id);

        $this->assertSame(['user', 'assistant'], Message::query()->withoutGlobalScopes()->orderBy('seq')->pluck('role')->all());
    }

    public function test_a_dead_socket_server_does_not_fail_the_job_and_the_stored_reply_is_still_complete(): void
    {
        Event::fake([ReplyToolStarted::class, ReplyRestarted::class, ReplyPaused::class, ReplyCompleted::class, ReplyFailed::class]);
        Event::listen(ReplyDelta::class, function (): void {
            throw new RuntimeException('Reverb is down');
        });
        Http::fakeSequence()->push($this->streamedAnswer('Seven days.'));
        $reply = $this->pendingReply();

        AnswerQuestion::dispatchSync($reply->id);

        $reply->refresh();
        $this->assertSame(Reply::STATUS_COMPLETED, $reply->status);
        $this->assertSame('Seven days.', $reply->content);
        $this->assertSame(['Seven days.'], app(ReplyBuffer::class)->chunks($reply->id));
    }

    private function pendingReply($user = null): Reply
    {
        return Reply::factory()
            ->forUser($user ?? $this->customerUser())
            ->create();
    }
}
