<?php

namespace Tests\Unit\Ai\Memory;

use App\Ai\Memory\StatedFactGuard;
use PHPUnit\Framework\TestCase;

class StatedFactGuardTest extends TestCase
{
    public function test_allows_a_fact_whose_words_the_customer_used(): void
    {
        $allowed = (new StatedFactGuard)->allows('role', 'bookkeeper for Acme Studios, not the owner', "I'm the bookkeeper at Acme, not the owner");

        $this->assertTrue($allowed);
    }

    public function test_allows_an_email_the_customer_typed(): void
    {
        $allowed = (new StatedFactGuard)->allows('billing_contact', 'billing@acme.example', 'Use billing@acme.example please');

        $this->assertTrue($allowed);
    }

    public function test_rejects_a_fact_that_only_the_assistant_said(): void
    {
        $allowed = (new StatedFactGuard)->allows('mood', 'frustrated about cash flow', 'Why is INV-4471 overdue?');

        $this->assertFalse($allowed);
    }

    public function test_rejects_a_key_that_is_not_snake_case(): void
    {
        $this->assertFalse((new StatedFactGuard)->allows('Preferred Name', 'Sam', 'call me Sam'));
        $this->assertFalse((new StatedFactGuard)->allows('1role', 'Sam', 'call me Sam'));
    }

    public function test_rejects_a_value_with_no_content_words(): void
    {
        $this->assertFalse((new StatedFactGuard)->allows('role', 'a', 'a'));
    }
}
