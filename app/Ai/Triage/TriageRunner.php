<?php

namespace App\Ai\Triage;

use App\Models\Ticket;
use App\Models\TriageRun;
use Throwable;

final class TriageRunner
{
    public function __construct(private readonly TriagePipeline $pipeline) {}

    public function triage(Ticket $ticket, string $eventId): TriageRun
    {
        $run = TriageRun::query()->firstOrNew(
            ['event_id' => $eventId],
            [
                'ticket_id' => $ticket->id,
                'status' => TriageRun::STATUS_RUNNING,
            ],
        );

        if (! $run->exists) {
            return $this->execute($ticket, $run);
        }

        if ($run->status === TriageRun::STATUS_FAILED) {
            return $this->execute($ticket, $run);
        }

        return $run;
    }

    private function execute(Ticket $ticket, TriageRun $run): TriageRun
    {
        $run->fill([
            'status' => TriageRun::STATUS_RUNNING,
            'error' => null,
        ])->save();

        try {
            $this->pipeline->run($ticket, $run);
        } catch (Throwable $exception) {
            $run->update([
                'status' => TriageRun::STATUS_FAILED,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $run->refresh();
    }
}
