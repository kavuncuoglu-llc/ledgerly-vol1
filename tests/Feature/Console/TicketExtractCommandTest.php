<?php

namespace Tests\Feature\Console;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class TicketExtractCommandTest extends TestCase
{
    use FakesChatCompletions;
    use RefreshDatabase;

    private string $samplesPath;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->samplesPath = tempnam(sys_get_temp_dir(), 'tickets');

        file_put_contents($this->samplesPath, json_encode([
            ['message' => 'Acme (acme@example.com) paid $1,284.50 on March 3 but invoice 4471 still shows unpaid', 'label' => 'billing'],
            ['message' => 'How do I add my logo?', 'label' => 'howto'],
            ['message' => 'Export is broken', 'label' => 'bug'],
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->samplesPath);

        parent::tearDown();
    }

    public function test_handle_reports_valid_repaired_and_failed_counts_and_filled_fields(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->completion($this->scriptedReply($request))));

        $this->artisan('ticket:extract', ['path' => $this->samplesPath])
            ->expectsOutput('3/3 valid · 0 repaired · 0 failed')
            ->expectsOutput('invoice_number filled: 1 customer_email filled: 1 amount_mentioned filled: 1 date_mentioned filled: 0')
            ->assertSuccessful();
    }

    public function test_handle_counts_a_repaired_ticket(): void
    {
        $answers = [
            $this->completion($this->ticketJson('billing', dateMentioned: 'March 3')),
            $this->completion($this->ticketJson('billing', '4471')),
            $this->completion($this->ticketJson('howto')),
            $this->completion($this->ticketJson('bug')),
        ];

        Http::fake(['*' => Http::sequence($answers)]);

        $this->artisan('ticket:extract', ['path' => $this->samplesPath])
            ->expectsOutput('3/3 valid · 1 repaired · 0 failed')
            ->assertSuccessful();

        $this->assertSame(1, ModelCall::query()->where('feature', 'ticket.extract')->where('repaired', true)->count());
    }

    public function test_handle_counts_a_ticket_that_stays_invalid_as_failed_and_keeps_going(): void
    {
        $answers = [
            $this->completion($this->ticketJson('nope')),
            $this->completion($this->ticketJson('nope')),
            $this->completion($this->ticketJson('howto')),
            $this->completion($this->ticketJson('bug')),
        ];

        Http::fake(['*' => Http::sequence($answers)]);

        $this->artisan('ticket:extract', ['path' => $this->samplesPath])
            ->expectsOutput('2/3 valid · 0 repaired · 1 failed')
            ->assertSuccessful();
    }

    public function test_handle_logs_every_call_under_ticket_extract_with_the_prompt_version(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->completion($this->scriptedReply($request))));

        $this->artisan('ticket:extract', ['path' => $this->samplesPath])->assertSuccessful();

        $this->assertSame(3, ModelCall::query()->where('feature', 'ticket.extract')->count());
        $this->assertSame(['2026-11-09.1'], ModelCall::query()->distinct()->pluck('prompt_version')->all());
    }

    public function test_handle_fails_when_the_file_is_missing(): void
    {
        Http::fake();

        $this->artisan('ticket:extract', ['path' => '/nonexistent/tickets.json'])
            ->expectsOutput('No file at /nonexistent/tickets.json.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_handle_fails_when_the_model_server_rejects_the_call(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $this->artisan('ticket:extract', ['path' => $this->samplesPath])->assertFailed();
    }

    public function test_handle_reads_the_default_sample_file_with_twenty_messages(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('other')))]);

        $this->artisan('ticket:extract')
            ->expectsOutput('20/20 valid · 0 repaired · 0 failed')
            ->assertSuccessful();

        Http::assertSentCount(20);
    }

    private function scriptedReply(Request $request): string
    {
        $messages = $request['messages'];

        return match (end($messages)['content']) {
            'How do I add my logo?' => $this->ticketJson('howto'),
            'Export is broken' => $this->ticketJson('bug'),
            default => $this->ticketJson('billing', '4471', 'acme@example.com', 1284.50),
        };
    }
}
