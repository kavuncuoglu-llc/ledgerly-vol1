<?php

namespace Tests\Unit\Ai\Vision;

use App\Ai\Exceptions\UnreadableInvoice;
use App\Ai\Vision\InvoicePrompt;
use PHPUnit\Framework\TestCase;

class InvoicePromptTest extends TestCase
{
    public function test_system_tells_the_model_to_use_null_and_never_guess(): void
    {
        $this->assertStringContainsString('Respond only with JSON', InvoicePrompt::system());
        $this->assertStringContainsString('use null. Never guess.', InvoicePrompt::system());
    }

    public function test_extract_names_every_field_the_command_reads(): void
    {
        foreach (['vendor_name', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'line_items', 'subtotal', 'tax', 'total'] as $field) {
            $this->assertStringContainsString($field, InvoicePrompt::extract());
        }
    }

    public function test_parse_reads_a_plain_json_reply(): void
    {
        $data = InvoicePrompt::parse('{"vendor_name": "Northgate Paper Co", "total": 1284.5}');

        $this->assertSame('Northgate Paper Co', $data['vendor_name']);
        $this->assertSame(1284.5, $data['total']);
    }

    public function test_parse_strips_markdown_fences_and_surrounding_prose(): void
    {
        $reply = "Here is the invoice:\n```json\n{\"invoice_number\": \"NP-20931\"}\n```\nLet me know if you need more.";

        $this->assertSame(['invoice_number' => 'NP-20931'], InvoicePrompt::parse($reply));
    }

    public function test_parse_throws_when_the_reply_has_no_json(): void
    {
        $this->expectException(UnreadableInvoice::class);

        InvoicePrompt::parse('I cannot read this image.');
    }

    public function test_parse_throws_when_the_braces_do_not_hold_valid_json(): void
    {
        $this->expectException(UnreadableInvoice::class);

        InvoicePrompt::parse('{vendor: Northgate}');
    }
}
