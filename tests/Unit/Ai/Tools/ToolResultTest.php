<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function test_ok_flattens_the_data_next_to_the_flag(): void
    {
        $result = ToolResult::ok(['found' => true, 'total' => 1284.5]);

        $this->assertSame('{"ok":true,"found":true,"total":1284.5}', $result->toJson());
    }

    public function test_error_carries_details_for_the_model_to_correct_itself(): void
    {
        $result = ToolResult::error('invalid arguments', ['month' => ['bad']]);

        $this->assertSame(['ok' => false, 'error' => 'invalid arguments', 'details' => ['month' => ['bad']]], $result->toArray());
    }

    public function test_denied_shows_the_model_only_not_permitted(): void
    {
        $result = ToolResult::denied('limit reached');

        $this->assertSame(['ok' => false, 'error' => 'not permitted'], $result->toArray());
        $this->assertSame('limit reached', $result->auditArray()['reason']);
    }

    public function test_declined_tells_the_model_the_user_declined(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'declined by user'], ToolResult::declined()->toArray());
    }

    public function test_pending_is_pending_and_not_ok(): void
    {
        $result = ToolResult::pending('abc', 'Refund', 'https://x.test/a');

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->ok);
        $this->assertFalse(ToolResult::ok()->isPending());
    }

    public function test_text_is_sent_to_the_model_verbatim_instead_of_as_json(): void
    {
        $result = ToolResult::text("Today is 2026-04-20.\ninvoices[1]{number}:\n  INV-4471");

        $this->assertTrue($result->ok);
        $this->assertTrue($result->isText());
        $this->assertSame("Today is 2026-04-20.\ninvoices[1]{number}:\n  INV-4471", $result->toJson());
    }

    public function test_text_is_audited_as_an_ok_result_carrying_the_text(): void
    {
        $result = ToolResult::text('rows');

        $this->assertSame(['ok' => true, 'text' => 'rows'], $result->auditArray());
        $this->assertFalse(ToolResult::ok(['a' => 1])->isText());
    }
}
