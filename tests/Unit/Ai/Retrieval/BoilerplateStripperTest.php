<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Embeddings\BoilerplateStripper;
use PHPUnit\Framework\TestCase;

class BoilerplateStripperTest extends TestCase
{
    public function test_strip_removes_lines_that_match_the_configured_patterns(): void
    {
        $stripper = new BoilerplateStripper(config_patterns());

        $stripped = $stripper->strip("Real content.\n\nWas this article helpful? Yes / No\n\nMore content.\n\nLast updated: 2026-01-01");

        $this->assertSame("Real content.\n\nMore content.", $stripped);
    }

    public function test_strip_leaves_text_alone_when_no_pattern_matches(): void
    {
        $this->assertSame('Plain text.', (new BoilerplateStripper(config_patterns()))->strip('Plain text.'));
    }

    public function test_count_reports_the_number_of_patterns(): void
    {
        $this->assertSame(2, (new BoilerplateStripper(config_patterns()))->count());
    }
}

function config_patterns(): array
{
    return [
        '/^\s*Was this (article|page) helpful\?.*$/mi',
        '/^\s*Last updated:.*$/mi',
    ];
}
