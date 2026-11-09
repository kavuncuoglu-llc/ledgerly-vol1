<?php

namespace Tests\Feature\Console;

use App\Models\AgentRun;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\TriageRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class TicketTriageCommandTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    private const BILLING = 'Hi, my client paid $1,284.50 but invoice 4471 still shows unpaid';

    private const HOWTO = 'How do I add my logo to the invoice template?';

    private const BUG = 'The PDF export button does nothing on Safari';

    private const OTHER = 'Can you recommend a good bookkeeper?';

    private string $samplesPath;

    private int $bugVotes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->samplesPath = tempnam(sys_get_temp_dir(), 'tickets');

        file_put_contents($this->samplesPath, json_encode([
            ['message' => self::BILLING, 'label' => 'billing'],
            ['message' => self::HOWTO, 'label' => 'howto'],
            ['message' => self::BUG, 'label' => 'bug'],
            ['message' => self::OTHER, 'label' => 'other'],
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->samplesPath);

        parent::tearDown();
    }

    public function test_it_prints_one_line_per_ticket_and_a_summary(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $this->fakeByMessage();

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])
            ->expectsOutputToContain('#1 billing (3/3) urgent → billing-team investigate: 2 rounds, 1 tool calls [agent:trace ')
            ->expectsOutputToContain('#2 howto (3/3) normal → docs-team no agent draft queued')
            ->expectsOutputToContain('#3 bug (2/3) — → HUMAN ambiguous category: bug/billing no draft')
            ->expectsOutputToContain('#4 other (3/3) — → HUMAN not a Ledgerly support request no draft')
            ->expectsOutputToContain('4 tickets · 2 automated · 2 to human · 1 agent runs · avg ')
            ->assertSuccessful();
    }

    public function test_it_creates_one_ticket_per_message_for_the_users_customer_and_a_triage_run_each(): void
    {
        $user = $this->customerUser();
        $this->fakeByMessage();

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])->assertSuccessful();

        $tickets = Ticket::query()->where('source', 'triage-sample')->get();
        $this->assertCount(4, $tickets);
        $this->assertSame([$user->customer_id], $tickets->pluck('customer_id')->unique()->all());
        $this->assertSame(4, TriageRun::query()->count());
    }

    public function test_running_it_twice_does_not_triage_again_without_fresh(): void
    {
        $user = $this->customerUser();
        $this->fakeByMessage();
        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])->assertSuccessful();
        $calls = ModelCall::query()->count();

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])->assertSuccessful();

        $this->assertSame($calls, ModelCall::query()->count());
        $this->assertSame(4, Ticket::query()->count());
        $this->assertSame(4, TriageRun::query()->count());
    }

    public function test_fresh_triages_the_same_messages_again(): void
    {
        $user = $this->customerUser();
        $this->fakeByMessage();
        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])->assertSuccessful();
        $calls = ModelCall::query()->count();

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id, '--fresh' => true])->assertSuccessful();

        $this->assertGreaterThan($calls, ModelCall::query()->count());
        $this->assertSame(4, Ticket::query()->count());
        $this->assertSame(4, TriageRun::query()->count());
    }

    public function test_it_reports_the_tokens_the_run_spent(): void
    {
        $user = $this->customerUser();
        $this->fakeByMessage();

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])
            ->expectsOutputToContain(' tokens · $0.00')
            ->assertSuccessful();
    }

    public function test_it_fails_for_an_unknown_user(): void
    {
        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => 'nobody@example.com'])
            ->expectsOutput('No such user.')
            ->assertFailed();
    }

    public function test_it_fails_when_the_samples_file_is_missing(): void
    {
        $user = $this->customerUser();

        $this->artisan('ticket:triage', ['path' => '/tmp/does-not-exist.json', '--user' => $user->id])
            ->assertFailed();
    }

    public function test_it_stops_with_the_error_when_the_model_server_is_down(): void
    {
        $user = $this->customerUser();
        $script = $this->fakeTriageModel(votes: ['billing']);
        $script['fail'] = true;

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])
            ->expectsOutputToContain('the model server is down')
            ->assertFailed();

        $this->assertSame(TriageRun::STATUS_FAILED, TriageRun::query()->firstOrFail()->status);
    }

    public function test_the_agent_run_it_points_to_exists_and_is_done(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $this->fakeByMessage();

        $this->artisan('ticket:triage', ['path' => $this->samplesPath, '--user' => $user->id])->assertSuccessful();

        $this->assertSame(AgentRun::STATUS_DONE, AgentRun::query()->sole()->status);
    }

    private function fakeByMessage(): void
    {
        $agentTurns = [
            $this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]),
            $this->answerResponse('INV-4471 is 18 days overdue.'),
        ];

        $script = $this->triageScript(votes: ['billing'], agentTurns: $agentTurns);

        Http::fake(function (Request $request) use ($script) {
            $messages = $request['messages'];

            $system = (string) $messages[0]['content'];

            $message = (string) end($messages)['content'];

            if (str_starts_with($system, 'You classify')) {
                return Http::response($this->completion($this->ticketJson($this->voteFor($message))));
            }

            if (str_starts_with($system, 'You extract')) {
                return Http::response($this->completion($this->extractionFor($message)));
            }

            return Http::response($this->triageReply($request, $script));
        });
    }

    private function voteFor(string $message): string
    {
        return match ($message) {
            self::BILLING => 'billing',
            self::HOWTO => 'howto',
            self::BUG => $this->nextBugVote(),
            default => 'other',
        };
    }

    private function nextBugVote(): string
    {
        return ['bug', 'billing', 'bug'][$this->bugVotes++ % 3];
    }

    private function extractionFor(string $message): string
    {
        return match ($message) {
            self::BILLING => $this->ticketJson('billing', '4471', null, 1284.50),
            default => $this->ticketJson('howto'),
        };
    }
}
