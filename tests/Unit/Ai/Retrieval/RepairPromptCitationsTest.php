<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\CitationReport;
use App\Ai\Structured\RepairPrompt;
use PHPUnit\Framework\TestCase;

class RepairPromptCitationsTest extends TestCase
{
    public function test_citations_returns_a_user_message_listing_every_error(): void
    {
        $message = RepairPrompt::citations(new CitationReport(errors: [
            'Citation [x] does not match any provided passage.',
            'Uncited factual sentence: "Fees are 2%."',
        ]));

        $this->assertSame('user', $message['role']);
        $this->assertStringContainsString("- Citation [x] does not match any provided passage.\n- Uncited factual sentence", $message['content']);
        $this->assertStringContainsString('offer to open a support ticket', $message['content']);
    }
}
