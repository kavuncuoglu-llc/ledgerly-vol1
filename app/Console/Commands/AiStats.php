<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\InvalidStatsWindow;
use App\Ai\Stats\FeatureSnapshot;
use App\Ai\Stats\FeatureStats;
use App\Ai\Stats\StatsWindow;
use Illuminate\Console\Command;

class AiStats extends Command
{
    private const ALERT_RISE = 0.25;

    protected $signature = 'ai:stats {--feature=support.reply : The feature name recorded in model_calls} {--since=24h : The window to report, such as 90m, 24h or 7d}';

    protected $description = 'Print the four context-engineering numbers for a feature: prompt cache hit rate, semantic cache hit rate, tokens per answer and time to first token';

    public function handle(FeatureStats $stats): int
    {
        try {
            $since = StatsWindow::since((string) $this->option('since'));
        } catch (InvalidStatsWindow $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $feature = (string) $this->option('feature');

        $window = $stats->between($feature, $since);

        $week = $stats->between($feature, StatsWindow::since('7d'));

        $this->printReport($feature, (string) $this->option('since'), $window, $week);

        return Command::SUCCESS;
    }

    private function printReport(string $feature, string $since, FeatureSnapshot $window, FeatureSnapshot $week): void
    {
        $this->line("feature {$feature}");
        $this->row('', "last {$since}", '7-day avg', 'note');
        $this->row('calls', number_format($window->calls), number_format((int) round($week->calls / 7)), 'per day in the 7-day column');
        $this->row('prompt cache hit rate', $this->percent($window->promptCacheHitRate), $this->percent($week->promptCacheHitRate), $this->promptCacheNote($window));
        $this->row('semantic cache hits', $this->percent($window->semanticHitRate), $this->percent($week->semanticHitRate), "{$window->semanticHits} of {$window->semanticLookups} cacheable (howto) questions");
        $this->row('tokens / answer', $this->number($window->tokensPerAnswer), $this->number($week->tokensPerAnswer), 'input tokens per reply, cache hits excluded');
        $this->line('by slot: '.$this->slots($window->slots));
        $this->row('time to first token', $this->milliseconds($window->averageTtftMs), $this->milliseconds($week->averageTtftMs), $this->ttftNote($window));

        $this->alertOnGrowth($window, $week);
    }

    private function row(string $label, string $window, string $week, string $note): void
    {
        $this->line(sprintf('%-24s%-14s%-14s%s', $label, $window, $week, $note));
    }

    private function promptCacheNote(FeatureSnapshot $window): string
    {
        if ($window->promptCacheHitRate === null) {
            return 'no conversation calls in the window';
        }

        if ($window->promptCacheEstimated) {
            return 'estimated locally from shared prefix bytes';
        }

        return 'reported by the provider';
    }

    private function ttftNote(FeatureSnapshot $window): string
    {
        if ($window->averageTtftMs !== null) {
            return 'streamed calls only';
        }

        if ($window->averageLatencyMs === null) {
            return 'no calls in the window';
        }

        return "non-streaming calls record no first-token time; average latency {$window->averageLatencyMs} ms";
    }

    private function slots(array $slots): string
    {
        if ($slots === []) {
            return 'no context breakdown recorded';
        }

        $parts = [];

        foreach ($slots as $slot => $tokens) {
            $parts[] = "{$slot} ".number_format($tokens);
        }

        return implode(' · ', $parts);
    }

    private function alertOnGrowth(FeatureSnapshot $window, FeatureSnapshot $week): void
    {
        if ($window->tokensPerAnswer === null) {
            return;
        }

        if ($week->tokensPerAnswer === null) {
            return;
        }

        if ($window->tokensPerAnswer <= $week->tokensPerAnswer * (1 + self::ALERT_RISE)) {
            return;
        }

        $rise = (int) round(($window->tokensPerAnswer / $week->tokensPerAnswer - 1) * 100);

        $this->warn("ALERT: tokens per answer is {$rise}% above the 7-day average.");
    }

    private function percent(?float $rate): string
    {
        if ($rate === null) {
            return 'n/a';
        }

        return round($rate * 100).'%';
    }

    private function number(?int $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value);
    }

    private function milliseconds(?int $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value).' ms';
    }
}
