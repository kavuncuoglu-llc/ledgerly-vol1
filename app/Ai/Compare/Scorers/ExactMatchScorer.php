<?php

namespace App\Ai\Compare\Scorers;

use App\Ai\Compare\Score;
use App\Ai\Contracts\ChatResponse;

final class ExactMatchScorer implements Scorer
{
    public function __construct(
        private readonly string $expected,
        private readonly string $field = 'category',
    ) {}

    public function name(): string
    {
        return 'exact';
    }

    public function score(ChatResponse $response): Score
    {
        $answer = $this->normalized($this->answerOf((string) $response->content));

        $expected = $this->normalized($this->expected);

        if ($answer === $expected) {
            return new Score($this->name(), 1.0, $answer);
        }

        return new Score($this->name(), 0.0, "expected {$expected}, got ".($answer === '' ? '(nothing)' : $answer));
    }

    private function answerOf(string $content): string
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return (string) ($decoded[$this->field] ?? '');
        }

        return $content;
    }

    private function normalized(string $value): string
    {
        return strtolower(trim($value, " \t\n\r\0\x0B\"'.`"));
    }
}
