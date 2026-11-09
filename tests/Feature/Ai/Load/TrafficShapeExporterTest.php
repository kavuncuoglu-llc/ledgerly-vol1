<?php

namespace Tests\Feature\Ai\Load;

use App\Ai\Load\TrafficShape;
use App\Ai\Load\TrafficShapeExporter;
use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrafficShapeExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_answered_call_becomes_a_shape_with_the_stable_prefix_from_its_breakdown(): void
    {
        $this->record([
            'prompt_tokens' => 2100,
            'completion_tokens' => 180,
            'context_breakdown' => ['system' => 450, 'memories' => 60, 'history' => 900, 'question' => 20],
        ]);

        $shapes = $this->export();

        $this->assertEquals([new TrafficShape('support.reply', 2100, 180, 510)], $shapes);
    }

    public function test_calls_without_a_breakdown_have_no_shared_prefix(): void
    {
        $this->record(['prompt_tokens' => 800, 'completion_tokens' => 40]);

        $this->assertSame(0, $this->export()[0]->prefixTokens);
    }

    public function test_the_prefix_never_exceeds_the_prompt(): void
    {
        $this->record(['prompt_tokens' => 300, 'completion_tokens' => 40, 'context_breakdown' => ['system' => 450]]);

        $this->assertSame(300, $this->export()[0]->prefixTokens);
    }

    public function test_failed_cached_and_tool_round_calls_are_left_out(): void
    {
        $this->record(['finish_reason' => 'job_failed', 'prompt_tokens' => 0, 'completion_tokens' => 0]);
        $this->record(['finish_reason' => 'tool_calls']);
        $this->record(['semantic_cache_hit' => true]);
        $this->record(['completion_tokens' => 0]);
        $this->record(['finish_reason' => 'length', 'prompt_tokens' => 700, 'completion_tokens' => 400]);

        $shapes = $this->export();

        $this->assertCount(1, $shapes);
        $this->assertSame(700, $shapes[0]->inputTokens);
    }

    public function test_other_features_and_older_calls_are_left_out(): void
    {
        $this->record(['feature' => 'ticket.classify']);
        $this->travelTo(now()->subDays(20));
        $this->record([]);
        $this->travelBack();
        $this->record(['prompt_tokens' => 555]);

        $shapes = $this->export();

        $this->assertCount(1, $shapes);
        $this->assertSame(555, $shapes[0]->inputTokens);
    }

    public function test_only_the_most_recent_calls_up_to_the_limit_are_kept(): void
    {
        foreach ([100, 200, 300] as $tokens) {
            $this->record(['prompt_tokens' => $tokens]);
        }

        $shapes = (new TrafficShapeExporter)->export('support.reply', now()->subDays(7), 2);

        $this->assertSame([300, 200], array_map(fn (TrafficShape $shape): int => $shape->inputTokens, $shapes));
    }

    private function export(): array
    {
        return (new TrafficShapeExporter)->export('support.reply', now()->subDays(7), 500);
    }

    private function record(array $attributes): void
    {
        ModelCall::create([
            'feature' => 'support.reply',
            'provider' => 'vllm',
            'model' => 'ledgerly-support',
            'prompt_tokens' => 1000,
            'completion_tokens' => 100,
            'finish_reason' => 'stop',
            'latency_ms' => 1200,
            ...$attributes,
        ]);
    }
}
