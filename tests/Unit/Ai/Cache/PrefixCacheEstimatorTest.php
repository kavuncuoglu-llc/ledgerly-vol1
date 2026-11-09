<?php

namespace Tests\Unit\Ai\Cache;

use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Tokens\RatioCounter;
use Tests\TestCase;

class PrefixCacheEstimatorTest extends TestCase
{
    public function test_estimate_is_zero_for_the_first_call_of_a_conversation(): void
    {
        $estimate = $this->estimator()->estimate('support.reply', 1, $this->prompt('first'), 100);

        $this->assertSame(0, $estimate);
    }

    public function test_estimate_counts_the_prefix_shared_with_the_previous_call(): void
    {
        $estimator = $this->estimator();
        $estimator->estimate('support.reply', 1, $this->prompt('first question'), 500);

        $estimate = $estimator->estimate('support.reply', 1, $this->prompt('second question'), 500);

        $this->assertGreaterThan(100, $estimate);
        $this->assertLessThan(500, $estimate);
    }

    public function test_estimate_is_the_whole_previous_prompt_when_the_new_one_only_appends(): void
    {
        $estimator = $this->estimator();
        $first = $this->prompt('first');
        $estimator->estimate('support.reply', 1, $first, 500);

        $estimate = $estimator->estimate('support.reply', 1, [
            ...$first,
            [
                'role' => 'assistant',
                'content' => 'a reply',
            ],
            [
                'role' => 'user',
                'content' => 'next',
            ],
        ], 500);

        $this->assertSame((new RatioCounter)->count($estimator->render($first)), $estimate);
    }

    public function test_estimate_collapses_when_a_changing_block_sits_before_the_history(): void
    {
        $estimator = $this->estimator();
        $history = [
            [
                'role' => 'user',
                'content' => str_repeat('an older question ', 40),
            ],
            [
                'role' => 'assistant',
                'content' => str_repeat('an older answer ', 40),
            ],
        ];
        $system = [
            [
                'role' => 'system',
                'content' => str_repeat('stable system text ', 20),
            ],
        ];

        $good = fn (string $context, string $question): array => [...$system, ...$history, $this->user($context), $this->user($question)];
        $bad = fn (string $context, string $question): array => [...$system, $this->user($context), ...$history, $this->user($question)];

        $estimator->estimate('good', 1, $good('context one', 'q1'), 2000);
        $goodEstimate = $estimator->estimate('good', 1, $good('context two', 'q2'), 2000);

        $estimator->estimate('bad', 1, $bad('context one', 'q1'), 2000);
        $badEstimate = $estimator->estimate('bad', 1, $bad('context two', 'q2'), 2000);

        $this->assertGreaterThan($badEstimate * 3, $goodEstimate);
    }

    public function test_estimate_never_exceeds_the_prompt_tokens_the_server_reported(): void
    {
        $estimator = $this->estimator();
        $estimator->estimate('support.reply', 1, $this->prompt('same'), 10);

        $estimate = $estimator->estimate('support.reply', 1, $this->prompt('same'), 10);

        $this->assertSame(10, $estimate);
    }

    public function test_estimate_keeps_conversations_and_features_apart(): void
    {
        $estimator = $this->estimator();
        $estimator->estimate('support.reply', 1, $this->prompt('same'), 500);

        $this->assertSame(0, $estimator->estimate('support.reply', 2, $this->prompt('same'), 500));
        $this->assertSame(0, $estimator->estimate('conversation.summarize', 1, $this->prompt('same'), 500));
    }

    public function test_shared_prefix_never_cuts_a_multibyte_character_in_half(): void
    {
        $shared = $this->estimator()->sharedPrefix('naïve café', 'naïve cafe');

        $this->assertSame('naïve caf', $shared);
        $this->assertTrue(mb_check_encoding($shared, 'UTF-8'));
    }

    private function estimator(): PrefixCacheEstimator
    {
        return new PrefixCacheEstimator(new RatioCounter);
    }

    private function prompt(string $question): array
    {
        return [
            [
                'role' => 'system',
                'content' => str_repeat('You are the support assistant. ', 20),
            ],
            $this->user($question),
        ];
    }

    private function user(string $content): array
    {
        return [
            'role' => 'user',
            'content' => $content,
        ];
    }
}
