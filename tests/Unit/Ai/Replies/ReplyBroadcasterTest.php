<?php

namespace Tests\Unit\Ai\Replies;

use App\Ai\Replies\ReplyBroadcaster;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class ReplyBroadcasterTest extends TestCase
{
    private int $attempts = 0;

    private bool $failing = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attempts = 0;
        $this->failing = false;

        config()->set('ai.replies.broadcast_pause_seconds', 5);

        Event::listen(stdClass::class, function (): void {
            $this->attempts++;

            if ($this->failing) {
                throw new RuntimeException('Reverb is down');
            }
        });
    }

    public function test_streaming_sends_the_event_through_the_dispatcher(): void
    {
        (new ReplyBroadcaster)->streaming(new stdClass);

        $this->assertSame(1, $this->attempts);
    }

    public function test_a_failing_socket_server_is_reported_and_does_not_throw(): void
    {
        $this->failing = true;
        Log::spy();

        (new ReplyBroadcaster)->streaming(new stdClass);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_after_a_failure_streaming_events_are_skipped_for_the_pause_window(): void
    {
        $this->failing = true;
        $broadcaster = new ReplyBroadcaster;

        $broadcaster->streaming(new stdClass);
        $broadcaster->streaming(new stdClass);
        $broadcaster->streaming(new stdClass);

        $this->assertSame(1, $this->attempts);
    }

    public function test_notify_always_tries_even_inside_the_pause_window(): void
    {
        $this->failing = true;
        $broadcaster = new ReplyBroadcaster;

        $broadcaster->streaming(new stdClass);
        $broadcaster->notify(new stdClass);

        $this->assertSame(2, $this->attempts);
    }

    public function test_a_zero_second_pause_keeps_trying_every_event(): void
    {
        config()->set('ai.replies.broadcast_pause_seconds', 0);
        $this->failing = true;
        $broadcaster = new ReplyBroadcaster;

        $broadcaster->streaming(new stdClass);
        $broadcaster->streaming(new stdClass);

        $this->assertSame(2, $this->attempts);
    }

    public function test_after_a_success_nothing_is_paused(): void
    {
        $broadcaster = new ReplyBroadcaster;

        $broadcaster->streaming(new stdClass);
        $broadcaster->streaming(new stdClass);

        $this->assertSame(2, $this->attempts);
    }
}
