<?php

namespace Tests\Unit\Ai\Context;

use App\Ai\Context\ContextBlock;
use PHPUnit\Framework\TestCase;

class ContextBlockTest extends TestCase
{
    public function test_render_labels_every_passage_with_its_source(): void
    {
        $block = ContextBlock::render([
            [
                'source' => 'billing/late-fees',
                'content' => 'Late fees are 1.5% per month.',
            ],
            [
                'source' => 'billing/disputes',
                'content' => 'A disputed invoice does not accrue late fees.',
            ],
        ]);

        $this->assertSame(
            "Context:\n[billing/late-fees] Late fees are 1.5% per month.\n\n[billing/disputes] A disputed invoice does not accrue late fees.",
            $block,
        );
    }
}
