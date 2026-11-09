<?php

namespace Tests\Feature\Ai\Triage;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Listeners\TriageTicket;
use App\Events\TicketReceived;
use App\Models\ModelCall;
use App\Models\TriageRun;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class TriageTicketListenerTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_the_triage_listener_is_queued_and_registered_for_received_tickets(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(TriageTicket::class));
        $this->assertTrue(Event::hasListeners(TicketReceived::class));
    }

    public function test_dispatching_the_event_runs_the_pipeline_and_stores_one_run(): void
    {
        $ticket = $this->ticketForOwner(['body' => 'How do I add my logo?']);
        $this->fakeTriageModel(votes: ['howto'], extraction: ['category' => 'howto', 'invoiceNumber' => null, 'amountMentioned' => null]);

        TicketReceived::dispatch($ticket);

        $run = TriageRun::query()->sole();
        $this->assertSame("ticket:{$ticket->id}", $run->event_id);
        $this->assertSame($ticket->id, $run->ticket_id);
        $this->assertSame(TriageRun::STATUS_QUEUED_FOR_REVIEW, $run->status);
    }

    public function test_a_duplicate_event_is_a_no_op(): void
    {
        $ticket = $this->ticketForOwner(['body' => 'How do I add my logo?']);
        $this->fakeTriageModel(votes: ['howto'], extraction: ['category' => 'howto', 'invoiceNumber' => null, 'amountMentioned' => null]);
        TicketReceived::dispatch($ticket);
        $callsAfterFirst = ModelCall::query()->count();

        TicketReceived::dispatch($ticket);
        TicketReceived::dispatch($ticket, 'ticket:'.$ticket->id);

        $this->assertSame(1, TriageRun::query()->count());
        $this->assertSame($callsAfterFirst, ModelCall::query()->count());
    }

    public function test_a_different_event_id_for_the_same_ticket_is_a_separate_run(): void
    {
        $ticket = $this->ticketForOwner(['body' => 'How do I add my logo?']);
        $this->fakeTriageModel(votes: ['howto'], extraction: ['category' => 'howto', 'invoiceNumber' => null, 'amountMentioned' => null]);

        TicketReceived::dispatch($ticket, 'webhook-1');
        TicketReceived::dispatch($ticket, 'webhook-2');

        $this->assertSame(2, TriageRun::query()->count());
    }

    public function test_a_failed_run_is_marked_failed_and_a_retry_reuses_the_row(): void
    {
        $ticket = $this->ticketForOwner(['body' => 'How do I add my logo?']);
        $script = $this->fakeTriageModel(votes: ['howto'], extraction: ['category' => 'howto', 'invoiceNumber' => null, 'amountMentioned' => null]);
        $script['fail'] = true;

        try {
            TicketReceived::dispatch($ticket);
            $this->fail('The model failure should reach the queue.');
        } catch (ModelRequestFailed) {
        }

        $failed = TriageRun::query()->sole();
        $this->assertSame(TriageRun::STATUS_FAILED, $failed->status);
        $this->assertNotEmpty($failed->error);

        $script['fail'] = false;
        TicketReceived::dispatch($ticket);

        $retried = TriageRun::query()->sole();
        $this->assertSame($failed->id, $retried->id);
        $this->assertSame(TriageRun::STATUS_QUEUED_FOR_REVIEW, $retried->status);
        $this->assertNull($retried->error);
    }

    public function test_the_failed_hook_reports_the_exception_so_it_alerts_instead_of_only_logging(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported): void {
            $reported[] = $exception->getMessage();
        })->stop();
        $ticket = $this->ticketForOwner();

        $this->app->make(TriageTicket::class)->failed(new TicketReceived($ticket), new RuntimeException('triage blew up'));

        $this->assertSame(['triage blew up'], $reported);
    }

    public function test_the_event_id_defaults_to_the_ticket(): void
    {
        $ticket = $this->ticketForOwner();

        $this->assertSame("ticket:{$ticket->id}", (new TicketReceived($ticket))->id);
        $this->assertSame('mail-9', (new TicketReceived($ticket, 'mail-9'))->id);
    }
}
