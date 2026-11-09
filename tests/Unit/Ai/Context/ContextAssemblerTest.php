<?php

namespace Tests\Unit\Ai\Context;

use App\Ai\Context\ContextAssembler;
use App\Ai\Retrieval\RetrievedChunk;
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
        $this->assertStringStartsWith('The following passages are from Ledgerly', $prompt->messages[4]['content']);
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

    public function test_build_never_writes_provider_markers_into_the_messages(): void
    {
        $prompt = $this->full()->build();

        foreach ($prompt->messages as $message) {
            $this->assertArrayNotHasKey('cache_control', $message);
        }
    }

    public function test_build_reports_a_breakpoint_after_the_system_and_summary_messages(): void
    {
        $prompt = $this->full()->build();

        $this->assertSame([0, 1], $prompt->breakpoints);
    }

    public function test_build_reports_only_the_system_message_when_there_is_no_summary(): void
    {
        $prompt = $this->assembler()
            ->system('SYSTEM')
            ->question('q')
            ->build();

        $this->assertSame([0], $prompt->breakpoints);
    }

    public function test_build_reports_no_breakpoints_without_stable_slots(): void
    {
        $prompt = $this->assembler()->question('q')->build();

        $this->assertSame([], $prompt->breakpoints);
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

        $this->assertStringContainsString('[a › Overview]', $context);
        $this->assertStringNotContainsString('[b › Overview]', $context);
        $this->assertStringNotContainsString('[c › Overview]', $context);
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

    private function full(): ContextAssembler
    {
        return $this->assembler()
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

    private function assembler(): ContextAssembler
    {
        return new ContextAssembler(counter: new RatioCounter(charsPerToken: 3.6));
    }

    private function chunk(string $source, string $content): RetrievedChunk
    {
        return new RetrievedChunk(
            id: crc32($source),
            source: "help/billing/{$source}.md",
            title: $source,
            headingPath: "Billing › {$source} › Overview",
            content: $content,
            helpVersion: 'abc123',
        );
    }
}
