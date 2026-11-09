<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnreadableTicketSamples;
use App\Ai\Support\TicketSamples;
use App\Ai\Triage\TriageRunner;
use App\Models\AgentRun;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TicketTriage extends Command
{
    private const DEFAULT_USER_EMAIL = 'acme@example.com';

    protected $signature = 'ticket:triage {path=storage/samples/tickets.json : A JSON list of {message, label} objects} {--user= : User id or email whose customer owns the tickets (default: acme@example.com)} {--fresh : Forget earlier triage runs for these messages and triage them again}';

    protected $description = 'Run the labeled sample support messages through the triage pipeline and print one line per ticket';

    public function handle(TriageRunner $runner): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No such user.');

            return Command::FAILURE;
        }

        $firstModelCallId = (int) ModelCall::query()->max('id');

        try {
            $runs = $this->triageEach($runner, $user, TicketSamples::fromFile($this->absolutePath((string) $this->argument('path'))));
        } catch (UnreadableTicketSamples|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->summary($runs, $firstModelCallId);

        return Command::SUCCESS;
    }

    private function triageEach(TriageRunner $runner, User $user, array $samples): array
    {
        $runs = [];

        foreach ($samples as $position => $sample) {
            $ticket = $this->ticketFor($user, $sample['message']);

            $this->forgetWhenFresh($ticket);

            $run = $runner->triage($ticket, "triage-sample:{$ticket->id}");

            $this->line($this->describe($position + 1, $run));

            $runs[] = $run;
        }

        return $runs;
    }

    private function ticketFor(User $user, string $message): Ticket
    {
        return Ticket::query()->firstOrCreate(
            [
                'idempotency_key' => "triage-sample:{$user->customer_id}:".sha1($message),
            ],
            [
                'number' => Ticket::nextNumber(),
                'customer_id' => $user->customer_id,
                'subject' => Str::limit($message, 80),
                'body' => $message,
                'status' => 'open',
                'received_at' => now(),
                'source' => 'triage-sample',
            ],
        );
    }

    private function forgetWhenFresh(Ticket $ticket): void
    {
        if (! $this->option('fresh')) {
            return;
        }

        TriageRun::query()->where('ticket_id', $ticket->id)->delete();
    }

    private function describe(int $position, TriageRun $run): string
    {
        $label = $run->category ?? 'unknown';

        $agreement = $this->agreementLabel($run);

        if ($run->status !== TriageRun::STATUS_QUEUED_FOR_REVIEW) {
            return "#{$position} {$label} ({$agreement}) — → HUMAN {$run->reason} no draft";
        }

        $urgency = $run->urgent
            ? 'urgent'
            : 'normal';

        return trim("#{$position} {$label} ({$agreement}) {$urgency} → {$run->assignee} {$this->investigation($run)} draft queued");
    }

    private function agreementLabel(TriageRun $run): string
    {
        $votes = $run->votes ?? [];

        if ($votes === []) {
            return '0/0';
        }

        $agreeing = (int) round(((float) $run->agreement) * count($votes));

        return $agreeing.'/'.count($votes);
    }

    private function investigation(TriageRun $run): string
    {
        $agent = AgentRun::query()->find($run->agent_run_id);

        if ($agent === null) {
            return 'no agent';
        }

        $calls = count($agent->state['steps'] ?? []);

        $line = "investigate: {$agent->rounds} rounds, {$calls} tool calls";

        if ($agent->status === AgentRun::STATUS_EXHAUSTED) {
            $line .= ", exhausted: {$agent->reason}";
        }

        return $line.' [agent:trace '.$agent->id.']';
    }

    private function summary(array $runs, int $firstModelCallId): void
    {
        $total = count($runs);

        $automated = collect($runs)->where('status', TriageRun::STATUS_QUEUED_FOR_REVIEW)->count();

        $agentRuns = collect($runs)->whereNotNull('agent_run_id')->count();

        $average = $total === 0
            ? 0.0
            : collect($runs)->avg('duration_ms') / 1000;

        $calls = ModelCall::query()->where('id', '>', $firstModelCallId);

        $tokens = (int) $calls->sum('prompt_tokens') + (int) $calls->sum('completion_tokens');

        $cost = (int) $calls->sum('cost_micros') / 1_000_000;

        $this->line(sprintf(
            '%d tickets · %d automated · %d to human · %d agent runs · avg %.1fs · %d tokens · $%.2f',
            $total,
            $automated,
            $total - $automated,
            $agentRuns,
            $average,
            $tokens,
            $cost,
        ));
    }

    private function resolveUser(): ?User
    {
        $given = (string) ($this->option('user') ?: self::DEFAULT_USER_EMAIL);

        if (ctype_digit($given)) {
            return User::find((int) $given);
        }

        return User::query()->where('email', $given)->first();
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
