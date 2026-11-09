<?php

namespace Tests\Feature\Console;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class TicketClassifyCommandTest extends TestCase
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
            ['message' => 'My card was charged twice', 'label' => 'billing'],
            ['message' => 'Can I invite my accountant?', 'label' => 'howto'],
            ['message' => 'Export is broken', 'label' => 'bug'],
            ['message' => 'I forgot my password', 'label' => 'account'],
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->samplesPath);

        parent::tearDown();
    }

    public function test_handle_prints_the_agreement_and_each_miss(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->completion($this->ticketJson($this->scriptedReply($request)))));

        $this->artisan('ticket:classify', ['path' => $this->samplesPath])
            ->expectsOutput('3/4 agree with human labels (75%)')
            ->expectsOutput('misses:')
            ->expectsOutput('  "Can I invite my accountant?" model: account human: howto')
            ->assertSuccessful();
    }

    public function test_handle_omits_the_misses_block_when_every_label_agrees(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->completion($this->ticketJson($this->perfectReply($request)))));

        $this->artisan('ticket:classify', ['path' => $this->samplesPath])
            ->expectsOutput('4/4 agree with human labels (100%)')
            ->doesntExpectOutputToContain('misses:')
            ->assertSuccessful();
    }

    public function test_handle_logs_every_classification_with_the_prompt_version(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('other')))]);

        $this->artisan('ticket:classify', ['path' => $this->samplesPath])->assertSuccessful();

        $this->assertSame(4, ModelCall::query()->where('feature', 'ticket.classify')->count());
        $this->assertSame(
            ['2026-11-01.1'],
            ModelCall::query()->distinct()->pluck('prompt_version')->all(),
        );
    }

    public function test_handle_fails_when_the_file_is_missing(): void
    {
        Http::fake();

        $this->artisan('ticket:classify', ['path' => '/nonexistent/tickets.json'])
            ->expectsOutput('No file at /nonexistent/tickets.json.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_handle_fails_when_the_file_is_not_a_labeled_list(): void
    {
        file_put_contents($this->samplesPath, json_encode([['message' => 'No label here']]));
        Http::fake();

        $this->artisan('ticket:classify', ['path' => $this->samplesPath])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_handle_fails_with_the_error_when_the_model_server_rejects_the_call(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $this->artisan('ticket:classify', ['path' => $this->samplesPath])->assertFailed();
    }

    public function test_handle_reads_the_default_sample_file_with_twenty_labeled_messages(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('other')))]);

        $this->artisan('ticket:classify')->assertSuccessful();

        Http::assertSentCount(20);
    }

    private function scriptedReply(Request $request): string
    {
        return match ($this->lastUserTurn($request)) {
            'My card was charged twice' => 'billing',
            'Can I invite my accountant?' => 'account',
            'Export is broken' => 'bug',
            default => 'account',
        };
    }

    private function perfectReply(Request $request): string
    {
        return match ($this->lastUserTurn($request)) {
            'My card was charged twice' => 'billing',
            'Can I invite my accountant?' => 'howto',
            'Export is broken' => 'bug',
            default => 'account',
        };
    }

    private function lastUserTurn(Request $request): string
    {
        $messages = $request['messages'];

        return end($messages)['content'];
    }
}
