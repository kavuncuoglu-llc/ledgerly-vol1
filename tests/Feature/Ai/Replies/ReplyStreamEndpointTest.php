<?php

namespace Tests\Feature\Ai\Replies;

use App\Ai\Replies\ReplyLifecycle;
use App\Ai\Streaming\ReplyBuffer;
use App\Ai\Streaming\ReplyEventStream;
use App\Models\PendingToolCall;
use App\Models\Reply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ReplyStreamEndpointTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_the_stream_route_replays_every_buffered_delta_then_the_completion(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed('Seven days.')->create(['sources' => [['label' => 'a', 'url' => '/a']]]);
        $this->buffered($reply, ['Seven ', 'days.']);

        $response = $this->actingAs($user)->get("replies/{$reply->id}/stream");

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame(
            "id: 0\nevent: ReplyDelta\ndata: {\"t\":\"Seven \",\"n\":0}\n\n"
            ."id: 1\nevent: ReplyDelta\ndata: {\"t\":\"days.\",\"n\":1}\n\n"
            ."event: ReplyCompleted\ndata: {\"sources\":[{\"label\":\"a\",\"url\":\"\\/a\"}],\"citations_ok\":true}\n\n",
            $response->streamedContent(),
        );
    }

    public function test_last_event_id_resumes_after_the_chunk_the_browser_already_has(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed('One two three')->create();
        $this->buffered($reply, ['One ', 'two ', 'three']);

        $content = $this->actingAs($user)
            ->withHeader('Last-Event-ID', '0')
            ->get("replies/{$reply->id}/stream")
            ->streamedContent();

        $this->assertStringNotContainsString('"t":"One "', $content);
        $this->assertStringContainsString('"t":"two "', $content);
        $this->assertStringContainsString('"t":"three"', $content);
    }

    public function test_the_after_query_parameter_does_the_same_for_a_first_connection(): void
    {
        $user = $this->userInTenant();
        $reply = Reply::factory()->forUser($user)->completed('One two')->create();
        $this->buffered($reply, ['One ', 'two']);

        $content = $this->actingAs($user)->get("replies/{$reply->id}/stream?after=1")->streamedContent();

        $this->assertStringNotContainsString('"t":"One "', $content);
        $this->assertStringContainsString('"t":"two"', $content);
    }

    public function test_the_stream_route_is_forbidden_for_another_user(): void
    {
        $owner = $this->userInTenant();
        $reply = Reply::factory()->forUser($owner)->completed()->create();

        $this->actingAs($this->userInTenant($owner->customer))->get("replies/{$reply->id}/stream")->assertForbidden();
    }

    public function test_the_stream_route_requires_authentication(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->completed()->create();

        $this->getJson("replies/{$reply->id}/stream")->assertUnauthorized();
    }

    public function test_a_failed_reply_ends_the_stream_with_its_message(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create([
            'status' => Reply::STATUS_FAILED,
            'content' => 'Sorry, something went wrong.',
        ]);

        $this->relay($reply);

        $this->assertSame(["event: ReplyFailed\ndata: {\"message\":\"Sorry, something went wrong.\"}\n\n"], $this->frames);
    }

    public function test_a_paused_reply_ends_the_stream_with_the_approvals_it_waits_for(): void
    {
        $user = $this->userInTenant();
        $token = '6f1b8c40-2c36-4a43-9e29-6e5b2b6f6a10';
        $reply = Reply::factory()->forUser($user)->paused($token, 'Refund $21.60')->create();
        $pending = PendingToolCall::create([
            'user_id' => $user->id,
            'run_id' => $token,
            'tool' => 'issue_refund',
            'tool_call_id' => 'call_1',
            'arguments' => [],
            'summary' => 'Refund $21.60',
            'status' => PendingToolCall::STATUS_PENDING,
        ]);

        $this->relay($reply);

        $this->assertCount(1, $this->frames);
        $this->assertStringContainsString('event: ReplyPaused', $this->frames[0]);
        $this->assertStringContainsString($pending->id, $this->frames[0]);
    }

    public function test_a_reply_that_is_still_streaming_is_polled_until_the_time_limit(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create(['status' => Reply::STATUS_STREAMING]);
        $this->buffered($reply, ['Hel']);
        config()->set('ai.replies.sse_max_seconds', 0);

        $this->relay($reply);

        $this->assertCount(1, $this->frames);
        $this->assertStringContainsString('event: ReplyDelta', $this->frames[0]);
    }

    public function test_the_relay_sleeps_between_polls_while_the_reply_is_in_flight(): void
    {
        $reply = Reply::factory()->forUser($this->userInTenant())->create(['status' => Reply::STATUS_STREAMING]);
        config()->set('ai.replies.sse_max_seconds', 1);
        Sleep::whenFakingSleep(function ($duration) use ($reply): void {
            $reply->update(['status' => Reply::STATUS_FAILED, 'content' => 'Stopped.']);
        });

        $this->relay($reply);

        Sleep::assertSleptTimes(1);
        $this->assertStringContainsString('event: ReplyFailed', $this->frames[0]);
    }

    private function buffered(Reply $reply, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            app(ReplyBuffer::class)->append($reply->id, $chunk);
        }
    }

    private function relay(Reply $reply): void
    {
        $stream = new ReplyEventStream(
            app(ReplyBuffer::class),
            app(ReplyLifecycle::class),
            function (string $frame): void {
                $this->frames[] = $frame;
            },
        );

        $stream->relay($reply);
    }
}
