<?php

namespace Tests\Unit\Ai\Vision;

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

    public function test_messages_put_the_image_data_url_in_the_user_turn_after_the_extraction_text(): void
    {
        $messages = InvoicePrompt::messages('data:image/jpeg;base64,AAAA');

        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame(InvoicePrompt::system(), $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame(['type' => 'text', 'text' => InvoicePrompt::extract()], $messages[1]['content'][0]);
        $this->assertSame('data:image/jpeg;base64,AAAA', $messages[1]['content'][1]['image_url']['url']);
    }

    public function test_extract_tells_the_model_how_to_refuse_through_the_error_field(): void
    {
        $this->assertStringContainsString('set error to a short reason', InvoicePrompt::extract());
    }
}
