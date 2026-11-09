<?php

namespace Tests\Unit\Ai\Context;

use App\Ai\Context\ContextAssembler;
use App\Ai\Context\ProviderCapabilities;
use App\Ai\Tokens\RatioCounter;
use Tests\TestCase;

class ContextAssemblerTest extends TestCase
{
    public function test_build_orders_the_slots_stable_first_and_the_question_last(): void
    {
        $prompt = $this->full()->build();

        $this->assertSame(
            ['system', 'system', 'user', 'assistant', 'user', 'user'],
            array_column($prompt->messages, 'role'),
        );
        $this->assertSame('SYSTEM', $prompt->messages[0]['content']);
        $this->assertSame('SUMMARY', $prompt->messages[1]['content']);
        $this->assertSame('older question', $prompt->messages[2]['content']);
        $this->assertStringStartsWith('Context:', $prompt->messages[4]['content']);
        $this->assertSame('the question', $prompt->messages[5]['content']);
    }

    public function test_build_puts_the_memories_block_inside_the_system_message(): void
    {
        $prompt = $this->assembler()
            ->system('SYSTEM')
            ->memories('MEMORIES')
            ->question('q')
            ->build();

        $this->assertCount(2, $prompt->messages);
        $this->assertSame("SYSTEM\n\nMEMORIES", $prompt->messages[0]['content']);
    }

    public function test_build_counts_tokens_per_slot(): void
    {
        $prompt = $this->assembler()
            ->system(str_repeat('a', 36))
            ->memories(str_repeat('b', 18))
            ->summary(str_repeat('c', 72))
            ->history([
                [
                    'role' => 'user',
                    'content' => str_repeat('d', 36),
                ],
            ])
            ->context([$this->chunk('billing/late-fees', 'Fees.')])
            ->question(str_repeat('e', 7))
            ->build();

        $this->assertSame(10, $prompt->breakdown['system']);
        $this->assertSame(5, $prompt->breakdown['memories']);
        $this->assertSame(20, $prompt->breakdown['summary']);
        $this->assertSame(10, $prompt->breakdown['history']);
        $this->assertSame(2, $prompt->breakdown['question']);
        $this->assertArrayHasKey('context', $prompt->breakdown);
        $this->assertSame(array_sum($prompt->breakdown), $prompt->total());
    }

    public function test_build_leaves_out_slots_that_are_empty(): void
    {
        $prompt = $this->assembler()
            ->system('SYSTEM')
            ->summary(null)
            ->history([])
            ->context([])
            ->question('q')
            ->build();

        $this->assertSame(['system', 'user'], array_column($prompt->messages, 'role'));
        $this->assertSame(['system', 'question'], array_keys($prompt->breakdown));
    }

    public function test_build_emits_no_breakpoints_when_the_provider_does_not_want_them(): void
    {
        $prompt = $this->full()->build();

        foreach ($prompt->messages as $message) {
            $this->assertArrayNotHasKey('cache_control', $message);
        }
    }

    public function test_build_marks_the_system_and_summary_messages_when_the_provider_wants_breakpoints(): void
    {
        $prompt = $this->full(new ProviderCapabilities('anthropic'))->build();

        $this->assertSame(['type' => 'ephemeral'], $prompt->messages[0]['cache_control']);
        $this->assertSame(['type' => 'ephemeral'], $prompt->messages[1]['cache_control']);

        foreach (array_slice($prompt->messages, 2) as $message) {
            $this->assertArrayNotHasKey('cache_control', $message);
        }
    }

    public function test_build_marks_only_the_system_message_when_there_is_no_summary(): void
    {
        $prompt = $this->assembler(new ProviderCapabilities('anthropic'))
            ->system('SYSTEM')
            ->question('q')
            ->build();

        $this->assertSame(['type' => 'ephemeral'], $prompt->messages[0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $prompt->messages[1]);
    }

    public function test_the_stable_prefix_is_byte_identical_when_only_the_context_and_question_change(): void
    {
        $first = $this->full()->context([$this->chunk('a', 'first passage')])->question('first')->build();
        $second = $this->full()->context([$this->chunk('b', 'another passage')])->question('second')->build();

        $this->assertSame(
            json_encode(array_slice($first->messages, 0, 4)),
            json_encode(array_slice($second->messages, 0, 4)),
        );
    }

    public function test_context_keeps_whole_chunks_until_the_slot_budget_is_spent(): void
    {
        config()->set('ai.slot_budgets.context', 20);

        $prompt = $this->assembler()
            ->system('SYSTEM')
            ->context([
                $this->chunk('a', str_repeat('x', 40)),
                $this->chunk('b', str_repeat('y', 40)),
                $this->chunk('c', str_repeat('z', 40)),
            ])
            ->question('q')
            ->build();

        $context = $prompt->messages[1]['content'];

        $this->assertStringContainsString('[a]', $context);
        $this->assertStringNotContainsString('[b]', $context);
        $this->assertStringNotContainsString('[c]', $context);
    }

    public function test_build_names_the_slots_that_are_over_their_budget(): void
    {
        config()->set('ai.budget.system', 5);
        config()->set('ai.slot_budgets.summary', 5);

        $prompt = $this->assembler()
            ->system(str_repeat('a', 72))
            ->summary(str_repeat('b', 72))
            ->question('q')
            ->build();

        $this->assertSame(['system', 'summary'], $prompt->overBudget);
    }

    public function test_history_tokens_include_tool_call_arguments(): void
    {
        $withoutCalls = $this->assembler()->system('s')->history([
            [
                'role' => 'assistant',
                'content' => 'ok',
            ],
        ])->build();

        $withCalls = $this->assembler()->system('s')->history([
            [
                'role' => 'assistant',
                'content' => 'ok',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'lookup_invoice',
                            'arguments' => '{"invoice_number":"INV-4471"}',
                        ],
                    ],
                ],
            ],
        ])->build();

        $this->assertGreaterThan($withoutCalls->breakdown['history'], $withCalls->breakdown['history']);
    }

    private function full(?ProviderCapabilities $provider = null): ContextAssembler
    {
        return $this->assembler($provider)
            ->system('SYSTEM')
            ->summary('SUMMARY')
            ->history([
                [
                    'role' => 'user',
                    'content' => 'older question',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'older answer',
                ],
            ])
            ->context([$this->chunk('billing/late-fees', 'Late fees are 1.5% per month.')])
            ->question('the question');
    }

    private function assembler(?ProviderCapabilities $provider = null): ContextAssembler
    {
        return new ContextAssembler(
            counter: new RatioCounter(charsPerToken: 3.6),
            provider: $provider ?? new ProviderCapabilities('ollama'),
        );
    }

    private function chunk(string $source, string $content): array
    {
        return [
            'source' => $source,
            'content' => $content,
        ];
    }
}
