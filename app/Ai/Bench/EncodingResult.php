<?php

namespace App\Ai\Bench;

final readonly class EncodingResult
{
    public function __construct(
        public string $name,
        public int $tokens,
        public int $correct,
        public int $asked,
        public array $missedQuestions = [],
        public int $modelPromptTokens = 0,
    ) {}

    public function tokensPerCorrect(): int
    {
        if ($this->correct === 0) {
            return 0;
        }

        return intdiv($this->tokens, $this->correct);
    }

    public function toArray(): array
    {
        return [
            'encoding' => $this->name,
            'tokens' => $this->tokens,
            'correct' => $this->correct,
            'asked' => $this->asked,
            'tokens_per_correct' => $this->tokensPerCorrect(),
            'missed_questions' => $this->missedQuestions,
            'model_prompt_tokens' => $this->modelPromptTokens,
        ];
    }
}
