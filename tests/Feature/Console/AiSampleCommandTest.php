<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSampleCommandTest extends TestCase
{
    public function test_handle_prints_one_line_per_run_with_finish_reason_and_tokens(): void
    {
        Http::fake([
            '*' => Http::response($this->completion('Reminder: INV-4471 is past due')),
        ]);

        $this->artisan('ai:sample', [
            'prompt' => 'Suggest a subject line.',
            '--runs' => 2,
        ])
            ->expectsOutput('[1] Reminder: INV-4471 is past due  stop · in 24 out 11')
            ->expectsOutput('[2] Reminder: INV-4471 is past due  stop · in 24 out 11')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_handle_sends_the_assistant_profile_by_default(): void
    {
        Http::fake([
            '*' => Http::response($this->completion('ok')),
        ]);

        $this->artisan('ai:sample', ['prompt' => 'Hi'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['temperature'] === 0.7 && $request['top_p'] === 0.9);
    }

    public function test_handle_lets_flags_override_the_profile(): void
    {
        Http::fake([
            '*' => Http::response($this->completion('billing')),
        ]);

        $this->artisan('ai:sample', [
            'prompt' => 'Classify',
            '--profile' => 'deterministic',
            '--temperature' => '1.3',
            '--max-tokens' => '5',
            '--seed' => '7',
            '--stop' => ['\n'],
        ])->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request['temperature'] === 1.3
                && $request['max_tokens'] === 5
                && $request['seed'] === 7
                && $request['stop'] === ["\n"];
        });
    }

    public function test_handle_prints_the_length_finish_reason_for_truncated_completions(): void
    {
        Http::fake([
            '*' => Http::response($this->completion('Late fees are charges', 'length')),
        ]);

        $this->artisan('ai:sample', [
            'prompt' => 'Explain how late fees work.',
            '--max-tokens' => '40',
        ])
            ->expectsOutput('[1] Late fees are charges  length · in 24 out 11')
            ->assertSuccessful();
    }

    public function test_handle_fails_for_an_unknown_profile_without_calling_the_model(): void
    {
        Http::fake();

        $this->artisan('ai:sample', [
            'prompt' => 'Hi',
            '--profile' => 'nope',
        ])
            ->expectsOutput('Unknown profile [nope].')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_handle_reports_a_model_server_error_and_fails(): void
    {
        Http::fake([
            '*' => Http::response('boom', 500),
        ]);

        $this->artisan('ai:sample', ['prompt' => 'Hi'])
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();
    }

    private function completion(string $content, string $finishReason = 'stop'): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $content,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => 24,
                'completion_tokens' => 11,
            ],
        ];
    }
}
