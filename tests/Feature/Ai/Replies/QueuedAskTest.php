<?php

namespace Tests\Feature\Ai\Replies;

use App\Ai\Events\ReplyCompleted;
use App\Ai\Events\ReplyDelta;
use App\Ai\Replies\AnswerQuestion;
use App\Ai\Streaming\ReplyBuffer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\Reply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\BuildsConversations;
use Tests\Support\FakesStreamedModel;
use Tests\TestCase;

class QueuedAskTest extends TestCase
{
    use BuildsConversations;
    use FakesStreamedModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
        config()->set('ai.replies.enabled', true);
    }

    public function test_ask_returns_202_with_the_reply_id_channel_and_conversation_without_calling_the_model(): void
    {
        Queue::fake();
        Http::fake();
        $user = $this->userInTenant();

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Why was I charged a late fee?']);

        $reply = Reply::query()->sole();
        $response->assertStatus(202)->assertExactJson([
            'reply_id' => $reply->id,
            'channel' => "reply.{$reply->id}",
            'conversation_id' => Conversation::query()->sole()->id,
            'status' => 'pending',
            'queue_depth' => 1,
        ]);
        Http::assertNothingSent();
    }

    public function test_ask_stores_a_pending_placeholder_for_the_question(): void
    {
        Queue::fake();
        $user = $this->userInTenant();

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Why was I charged a late fee?']);

        $reply = Reply::query()->sole();
        $this->assertSame('pending', $reply->status);
        $this->assertSame('Why was I charged a late fee?', $reply->question);
        $this->assertSame($user->id, $reply->user_id);
        $this->assertNull($reply->content);
    }

    public function test_ask_dispatches_the_answer_job_for_the_reply_on_the_ai_queue(): void
    {
        Queue::fake();

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        $reply = Reply::query()->sole();
        Queue::assertPushedOn('ai', AnswerQuestion::class, fn (AnswerQuestion $job): bool => $job->replyId === $reply->id);
    }

    public function test_ask_adds_the_reply_to_an_existing_open_conversation(): void
    {
        Queue::fake();
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);

        $response = $this->actingAs($user)->postJson('support/ask', [
            'question' => 'And the next month?',
            'conversation_id' => $conversation->id,
        ]);

        $response->assertStatus(202)->assertJson(['conversation_id' => $conversation->id]);
        $this->assertSame($conversation->id, Reply::query()->sole()->conversation_id);
        $this->assertSame(1, Conversation::query()->count());
    }

    public function test_a_double_submit_with_the_same_request_id_makes_one_reply_and_one_job(): void
    {
        Queue::fake();
        $user = $this->userInTenant();
        $payload = [
            'question' => 'Why was I charged a late fee?',
            'request_id' => (string) Str::uuid(),
        ];

        $first = $this->actingAs($user)->postJson('support/ask', $payload);
        $second = $this->actingAs($user)->postJson('support/ask', $payload);

        $this->assertSame($first->json('reply_id'), $second->json('reply_id'));
        $this->assertSame(1, Reply::query()->count());
        $this->assertSame(1, Conversation::query()->count());
        Queue::assertPushed(AnswerQuestion::class, 1);
    }

    public function test_two_different_request_ids_make_two_replies(): void
    {
        Queue::fake();
        $user = $this->userInTenant();

        $this->actingAs($user)->postJson('support/ask', ['question' => 'One', 'request_id' => (string) Str::uuid()]);
        $this->actingAs($user)->postJson('support/ask', ['question' => 'Two', 'request_id' => (string) Str::uuid()]);

        $this->assertSame(2, Reply::query()->count());
    }

    public function test_ask_reports_how_many_jobs_are_waiting_on_the_ai_queue_including_its_own(): void
    {
        Queue::fake();
        Queue::pushOn('ai', new AnswerQuestion('earlier-1'));
        Queue::pushOn('ai', new AnswerQuestion('earlier-2'));

        $response = $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        $response->assertStatus(202)->assertJson(['queue_depth' => 3]);
    }

    public function test_a_long_queue_writes_a_warning_to_the_log(): void
    {
        Queue::fake();
        Log::spy();
        config()->set('ai.replies.queue_depth_alert', 1);

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Hi']);

        Log::shouldHaveReceived('warning')->once()->with('The ai queue is backing up.', ['depth' => 1, 'queue' => 'ai']);
    }

    public function test_ask_requires_authentication(): void
    {
        Queue::fake();

        $this->postJson('support/ask', ['question' => 'Hi'])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_ask_validates_the_question_and_the_request_id(): void
    {
        Queue::fake();

        $this->actingAs($this->userInTenant())
            ->postJson('support/ask', ['request_id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['question', 'request_id']);
    }

    public function test_ask_rejects_another_users_conversation(): void
    {
        Queue::fake();
        $other = $this->conversationFor($this->userInTenant());

        $this->actingAs($this->userInTenant())
            ->postJson('support/ask', ['question' => 'Hi', 'conversation_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conversation_id']);
    }

    public function test_with_a_synchronous_queue_the_whole_reply_streams_and_completes(): void
    {
        Event::fake([ReplyDelta::class, ReplyCompleted::class]);
        Http::fakeSequence()->push($this->streamedAnswer('Late fees apply after seven days.'));
        $user = $this->userInTenant();

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Why was I charged a late fee?']);

        $response->assertStatus(202);
        $reply = Reply::query()->sole();
        $this->assertSame('completed', $reply->status);
        $this->assertSame('Late fees apply after seven days.', $reply->content);
        $this->assertSame(20, $reply->tokens);
        $this->assertNotNull($reply->completed_at);
        Event::assertDispatched(ReplyCompleted::class, fn (ReplyCompleted $event): bool => $event->replyId === $reply->id && $event->citationsOk);
    }

    public function test_the_streamed_deltas_rebuild_the_answer_in_order_with_a_sequence_number_each(): void
    {
        Event::fake([ReplyDelta::class, ReplyCompleted::class]);
        config()->set('ai.replies.flush_tokens', 2);
        Http::fakeSequence()->push($this->streamedAnswer('Late fees apply after seven days.'));

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Late fees?']);

        $replyId = Reply::query()->sole()->id;
        $dispatched = [];
        Event::assertDispatched(ReplyDelta::class, function (ReplyDelta $event) use (&$dispatched): bool {
            $dispatched[$event->sequence] = $event->text;

            return true;
        });
        ksort($dispatched);
        $this->assertSame([0, 1, 2], array_keys($dispatched));
        $this->assertSame('Late fees apply after seven days.', implode('', $dispatched));
        $this->assertSame($dispatched, app(ReplyBuffer::class)->chunks($replyId));
    }

    public function test_batching_sends_fewer_frames_than_the_model_sent_deltas(): void
    {
        Event::fake([ReplyDelta::class, ReplyCompleted::class]);
        $words = array_map(fn (int $number): string => "w{$number} ", range(1, 45));
        Http::fakeSequence()->push($this->streamedAnswer(implode('', $words), $words));

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Long one']);

        Event::assertDispatchedTimes(ReplyDelta::class, 3);
    }

    public function test_the_conversation_gets_the_question_and_the_final_answer_once(): void
    {
        Event::fake([ReplyDelta::class, ReplyCompleted::class]);
        Http::fakeSequence()->push($this->streamedAnswer('Seven days.'));
        $user = $this->userInTenant();

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Grace period?']);

        $stored = Message::query()->withoutGlobalScopes()->orderBy('seq')->get();
        $this->assertSame(['user', 'assistant'], $stored->pluck('role')->all());
        $this->assertSame(['Grace period?', 'Seven days.'], $stored->pluck('content')->all());
    }

    public function test_the_streamed_model_call_is_recorded_with_the_conversation_and_time_to_first_token(): void
    {
        Event::fake([ReplyDelta::class, ReplyCompleted::class]);
        Http::fakeSequence()->push($this->streamedAnswer('Seven days.'));

        $this->actingAs($this->userInTenant())->postJson('support/ask', ['question' => 'Grace period?']);

        $call = ModelCall::query()->sole();
        $this->assertSame('support.reply', $call->feature);
        $this->assertSame(Conversation::query()->sole()->id, $call->conversation_id);
        $this->assertNotNull($call->ttft_ms);
        $this->assertSame(80, $call->prompt_tokens);
    }
}
