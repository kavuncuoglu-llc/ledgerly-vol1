<?php

namespace Tests\Unit\Ai\Replies;

use App\Ai\Events\ReplyCompleted;
use App\Ai\Events\ReplyDelta;
use App\Ai\Events\ReplyFailed;
use App\Ai\Events\ReplyPaused;
use App\Ai\Events\ReplyRestarted;
use App\Ai\Events\ReplyToolStarted;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReplyEventsTest extends TestCase
{
    public static function events(): array
    {
        return [
            'delta' => [new ReplyDelta('r1', 'Hi', 3), 'ReplyDelta', ['t' => 'Hi', 'n' => 3]],
            'tool' => [new ReplyToolStarted('r1', 'lookup_invoice', 'Looking up your invoice...'), 'ToolCallStarted', ['tool' => 'lookup_invoice', 'label' => 'Looking up your invoice...']],
            'restarted' => [new ReplyRestarted('r1'), 'ReplyRestarted', []],
            'paused' => [new ReplyPaused('r1', 'Refund', [['id' => 'p1']]), 'ReplyPaused', ['summary' => 'Refund', 'approvals' => [['id' => 'p1']]]],
            'completed' => [new ReplyCompleted('r1', [['label' => 'a', 'url' => '/a']], false), 'ReplyCompleted', ['sources' => [['label' => 'a', 'url' => '/a']], 'citations_ok' => false]],
            'failed' => [new ReplyFailed('r1', 'Sorry'), 'ReplyFailed', ['message' => 'Sorry']],
        ];
    }

    #[DataProvider('events')]
    public function test_each_event_broadcasts_now_on_the_private_reply_channel_under_its_short_name(ShouldBroadcastNow $event, string $name, array $payload): void
    {
        $channels = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-reply.r1', $channels[0]->name);
        $this->assertSame($name, $event->broadcastAs());
        $this->assertSame($payload, $event->broadcastWith());
    }
}
