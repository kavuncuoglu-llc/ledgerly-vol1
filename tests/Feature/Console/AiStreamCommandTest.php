<?php

namespace Tests\Feature\Console;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AiStreamCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_prints_the_deltas_then_the_finish_line(): void
    {
        Http::fake(['*' => Http::response($this->stream([
            ['content' => 'Late fees'],
            ['content' => ' are charges.'],
        ], done: true))]);

        $exitCode = Artisan::call('ai:stream', ['prompt' => 'Explain how late fees work.']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Late fees are charges.\n[stop · in 18 out 142]", Artisan::output());
    }

    public function test_handle_logs_the_call_under_cli_stream(): void
    {
        Http::fake(['*' => Http::response($this->stream([['content' => 'ok']], done: true))]);

        $this->artisan('ai:stream', ['prompt' => 'Hi'])->assertSuccessful();

        $this->assertSame('cli.stream', ModelCall::query()->sole()->feature);
    }

    public function test_handle_warns_when_the_stream_ends_without_done(): void
    {
        Http::fake(['*' => Http::response($this->stream([['content' => 'The fee is 1.5% of the']], done: false))]);

        $this->artisan('ai:stream', ['prompt' => 'Hi'])
            ->expectsOutputToContain('The fee is 1.5% of the')
            ->expectsOutputToContain('[stream cut off before completion]')
            ->assertFailed();
    }

    public function test_handle_reports_an_unknown_profile(): void
    {
        Http::fake();

        $this->artisan('ai:stream', ['prompt' => 'Hi', '--profile' => 'nope'])
            ->expectsOutputToContain('Unknown sampling profile [nope].')
            ->assertFailed();
    }

    public function test_handle_reports_a_rejected_request(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $this->artisan('ai:stream', ['prompt' => 'Hi'])
            ->expectsOutputToContain('HTTP 404')
            ->assertFailed();
    }

    private function stream(array $deltas, bool $done): string
    {
        $lines = array_map(
            fn (array $delta): string => 'data: '.json_encode([
                'id' => 'chatcmpl-1',
                'model' => 'llama3.1:8b',
                'choices' => [['delta' => $delta, 'finish_reason' => null]],
            ])."\n\n",
            $deltas,
        );

        $lines[] = 'data: '.json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [['delta' => [], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 18, 'completion_tokens' => 142],
        ])."\n\n";

        if ($done) {
            $lines[] = "data: [DONE]\n\n";
        }

        return implode('', $lines);
    }
}
