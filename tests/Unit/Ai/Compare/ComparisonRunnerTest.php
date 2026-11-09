<?php

namespace Tests\Unit\Ai\Compare;

use App\Ai\Compare\CompareCase;
use App\Ai\Compare\ComparisonRunner;
use App\Ai\Compare\Scorers\ExactMatchScorer;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Facades\Ai;
use Tests\TestCase;

class ComparisonRunnerTest extends TestCase
{
    public function test_every_provider_answers_every_case_and_is_scored(): void
    {
        Ai::fake()
            ->for('compare.ticket.classify')->respondWithJson(['category' => 'billing'])
            ->for('compare.ticket.classify')->respondWithJson(['category' => 'howto'])
            ->for('compare.ticket.classify')->respondWithJson(['category' => 'billing'])
            ->for('compare.ticket.classify')->respondWithJson(['category' => 'billing']);

        $outcomes = app(ComparisonRunner::class)->run([$this->case('1', 'billing'), $this->case('2', 'billing')], ['local', 'frontier']);

        $this->assertCount(4, $outcomes);
        $this->assertSame(['local', 'local', 'frontier', 'frontier'], array_map(fn ($outcome): string => $outcome->provider, $outcomes));
        $this->assertSame([1.0, 0.0, 1.0, 1.0], array_map(fn ($outcome): float => $outcome->quality, $outcomes));
        $this->assertGreaterThan(0, $outcomes[0]->promptTokens);
    }

    public function test_the_same_request_goes_to_every_provider_under_a_compare_feature_name(): void
    {
        Ai::fake()->respondWithJson(['category' => 'billing'], times: 2);

        app(ComparisonRunner::class)->run([$this->case('1', 'billing')], ['local', 'frontier']);

        Ai::assertSentCount('compare.ticket.classify', 2);
        Ai::assertNotSent(fn (ChatRequest $request): bool => $request->feature === 'ticket.classify');
    }

    public function test_a_failing_provider_is_recorded_as_an_error_and_given_up_on_after_three_in_a_row(): void
    {
        Ai::fake()->failWith(new ProviderUnavailable('down'), times: 3);
        $cases = array_map(fn (int $number): CompareCase => $this->case((string) $number, 'billing'), range(1, 5));

        $outcomes = app(ComparisonRunner::class)->run($cases, ['local']);

        $this->assertCount(5, $outcomes);
        $this->assertSame('down', $outcomes[0]->error);
        $this->assertStringContainsString('skipped after 3 failures', $outcomes[4]->error);
        Ai::assertSentCount('compare.ticket.classify', 3);
    }

    public function test_streaming_measures_time_to_first_token(): void
    {
        Ai::fake()->respondWithStream(['{"category":', '"billing"}']);

        $outcomes = app(ComparisonRunner::class)->run([$this->case('1', 'billing')], ['local'], stream: true);

        $this->assertNotNull($outcomes[0]->ttftMs);
        $this->assertSame(1.0, $outcomes[0]->quality);
    }

    public function test_a_case_marked_sensitive_keeps_no_answer_text(): void
    {
        Ai::fake()->respondWithJson(['category' => 'billing'], times: 2);
        $open = $this->case('1', 'billing');
        $private = new CompareCase('2', 'ticket.classify', 'billing', $open->request, $open->scorers, sensitive: true);

        $outcomes = app(ComparisonRunner::class)->run([$open, $private], ['local']);

        $this->assertNotSame('', $outcomes[0]->answer);
        $this->assertSame('', $outcomes[1]->answer);
    }

    public function test_progress_is_reported_per_case(): void
    {
        Ai::fake()->respondWithJson(['category' => 'billing'], times: 2);
        $seen = [];

        app(ComparisonRunner::class)->run([$this->case('1', 'billing'), $this->case('2', 'billing')], ['local'], progress: function (string $provider, CompareCase $case) use (&$seen): void {
            $seen[] = "{$provider}:{$case->id}";
        });

        $this->assertSame(['local:1', 'local:2'], $seen);
    }

    private function case(string $id, string $expected): CompareCase
    {
        return new CompareCase(
            id: $id,
            feature: 'ticket.classify',
            category: $expected,
            request: new ChatRequest(messages: [['role' => 'user', 'content' => 'Why was I charged?']], feature: 'ticket.classify'),
            scorers: [new ExactMatchScorer($expected)],
        );
    }
}
