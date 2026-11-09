<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Retrieval\IdentifierQuestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentifierQuestionTest extends TestCase
{
    #[DataProvider('identifierQuestions')]
    public function test_in_detects_identifiers(string $question): void
    {
        $this->assertTrue(IdentifierQuestion::in($question));
    }

    #[DataProvider('plainQuestions')]
    public function test_in_ignores_plain_questions(string $question): void
    {
        $this->assertFalse(IdentifierQuestion::in($question));
    }

    public static function identifierQuestions(): array
    {
        return [
            'invoice number' => ['why is INV-4471 overdue'],
            'bare invoice' => ['status of invoice 4471'],
            'ticket' => ['update on T-10422'],
            'hash number' => ['what happened to #4471'],
            'email' => ['reset the password for acme@example.com'],
            'long number' => ['payment 20260112 failed'],
        ];
    }

    public static function plainQuestions(): array
    {
        return [
            'late fee' => ['my client keeps paying me late, what can I do'],
            'percent' => ['is 1.5% charged every month'],
            'short number' => ['can I add 3 users'],
        ];
    }
}
