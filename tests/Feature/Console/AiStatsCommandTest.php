<?php

namespace Tests\Feature\Console;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AiStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_the_four_numbers_for_the_feature(): void
    {
        $this->record(['prompt_tokens' => 1000, 'cached_tokens' => 700, 'cached_tokens_estimated' => true, 'ttft_ms' => 400, 'context_breakdown' => ['system' => 300, 'history' => 500, 'question' => 20]]);
        $this->record(['prompt_tokens' => 1000, 'cached_tokens' => 500, 'cached_tokens_estimated' => true, 'ttft_ms' => 600, 'context_breakdown' => ['system' => 300, 'history' => 700, 'question' => 40]]);
        $this->record(['prompt_tokens' => 0, 'semantic_cache_hit' => true]);
        $this->record(['prompt_tokens' => 1000, 'semantic_cache_hit' => false]);

        $output = $this->stats();

        $this->assertStringContainsString('feature support.reply', $output);
        $this->assertMatchesRegularExpression('/calls\s+4\s/', $output);
        $this->assertMatchesRegularExpression('/prompt cache hit rate\s+60%.*estimated locally from shared prefix bytes/', $output);
        $this->assertMatchesRegularExpression('/semantic cache hits\s+50%.*1 of 2 cacheable/', $output);
        $this->assertMatchesRegularExpression('/tokens \/ answer\s+1,000\s/', $output);
        $this->assertStringContainsString('by slot: system 300 · history 600 · question 30', $output);
        $this->assertMatchesRegularExpression('/time to first token\s+500 ms/', $output);
    }

    public function test_it_reports_provider_figures_without_the_estimate_note(): void
    {
        $this->record(['prompt_tokens' => 1000, 'cached_tokens' => 900, 'cached_tokens_estimated' => false]);

        $this->assertMatchesRegularExpression('/prompt cache hit rate\s+90%.*reported by the provider/', $this->stats());
    }

    public function test_it_leaves_other_features_and_older_calls_out_of_the_window(): void
    {
        $this->record(['prompt_tokens' => 1000, 'cached_tokens' => 500, 'cached_tokens_estimated' => true]);
        $this->record(['feature' => 'ticket.classify', 'prompt_tokens' => 9000, 'cached_tokens' => 0]);
        $this->travelTo(now()->subDays(3));
        $this->record(['prompt_tokens' => 9000, 'cached_tokens' => 0, 'cached_tokens_estimated' => true]);
        $this->travelBack();

        $output = $this->stats();

        $this->assertMatchesRegularExpression('/calls\s+1\s/', $output);
        $this->assertMatchesRegularExpression('/prompt cache hit rate\s+50%/', $output);
    }

    public function test_it_says_first_token_time_is_not_recorded_for_non_streaming_calls(): void
    {
        $this->record(['prompt_tokens' => 1000, 'latency_ms' => 3200]);

        $this->assertStringContainsString('non-streaming calls record no first-token time; average latency 3200 ms', $this->stats());
    }

    public function test_it_prints_not_available_when_there_is_nothing_in_the_window(): void
    {
        $output = $this->stats();

        $this->assertMatchesRegularExpression('/prompt cache hit rate\s+n\/a/', $output);
        $this->assertMatchesRegularExpression('/semantic cache hits\s+n\/a/', $output);
        $this->assertStringContainsString('no context breakdown recorded', $output);
    }

    public function test_it_warns_when_tokens_per_answer_rose_more_than_a_quarter_over_the_week(): void
    {
        $this->record(['prompt_tokens' => 3000]);
        $this->travelTo(now()->subDays(2));
        foreach (range(1, 4) as $ignored) {
            $this->record(['prompt_tokens' => 1000]);
        }
        $this->travelBack();

        $this->assertStringContainsString('ALERT: tokens per answer is 114% above the 7-day average.', $this->stats());
    }

    public function test_it_does_not_warn_when_tokens_per_answer_is_steady(): void
    {
        $this->record(['prompt_tokens' => 1100]);
        $this->travelTo(now()->subDays(2));
        $this->record(['prompt_tokens' => 1000]);
        $this->travelBack();

        $this->assertStringNotContainsString('ALERT', $this->stats());
    }

    public function test_it_does_not_count_the_tool_call_rounds_as_answers(): void
    {
        $this->record(['prompt_tokens' => 800, 'finish_reason' => 'tool_calls']);
        $this->record(['prompt_tokens' => 1000, 'finish_reason' => 'stop']);

        $this->assertMatchesRegularExpression('/tokens \/ answer\s+1,800\s/', $this->stats());
    }

    public function test_it_rejects_a_window_it_cannot_read(): void
    {
        $this->artisan('ai:stats', ['--since' => 'lately'])
            ->expectsOutputToContain('[lately] is not a window')
            ->assertFailed();
    }

    public function test_it_reports_the_citation_flag_rate_over_checked_replies_only(): void
    {
        $this->record(['citation_errors' => 0]);
        $this->record(['citation_errors' => 2]);
        $this->record(['citation_errors' => 0]);
        $this->record(['citation_errors' => 0]);
        $this->record([]);

        $this->assertMatchesRegularExpression('/citation flag rate\s+25%.*1 of 4 checked replies/', $this->stats());
    }

    public function test_it_reports_no_citation_rate_before_any_reply_was_checked(): void
    {
        $this->record([]);

        $this->assertMatchesRegularExpression('/citation flag rate\s+n\/a/', $this->stats());
    }

    private function stats(): string
    {
        Artisan::call('ai:stats', ['--feature' => 'support.reply', '--since' => '24h']);

        return Artisan::output();
    }

    private function record(array $attributes): ModelCall
    {
        return ModelCall::create([
            'feature' => 'support.reply',
            'provider' => 'ollama',
            'model' => 'llama3.1:8b',
            'prompt_tokens' => 100,
            'completion_tokens' => 20,
            'finish_reason' => 'stop',
            'latency_ms' => 1000,
            ...$attributes,
        ]);
    }
}
