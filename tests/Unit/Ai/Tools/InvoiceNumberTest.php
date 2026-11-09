<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\InvoiceNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceNumberTest extends TestCase
{
    #[DataProvider('writtenForms')]
    public function test_normalize_turns_every_written_form_into_the_stored_form(string $written, string $expected): void
    {
        $this->assertSame($expected, InvoiceNumber::normalize($written));
    }

    public static function writtenForms(): array
    {
        return [
            ['4471', 'INV-4471'],
            ['#4471', 'INV-4471'],
            ['INV-4471', 'INV-4471'],
            ['inv4471', 'INV-4471'],
            ['# 04471', 'INV-4471'],
            ['0', 'INV-0'],
        ];
    }

    #[DataProvider('rejectedForms')]
    public function test_pattern_rejects_anything_that_is_not_an_invoice_number(string $written): void
    {
        $this->assertSame(0, preg_match(InvoiceNumber::PATTERN, $written));
    }

    public static function rejectedForms(): array
    {
        return [
            ['Acme'],
            ['4471; drop'],
            ['INV-'],
            ['12345678901'],
            [''],
        ];
    }
}
