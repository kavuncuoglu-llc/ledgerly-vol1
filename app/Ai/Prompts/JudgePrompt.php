<?php

namespace App\Ai\Prompts;

final class JudgePrompt
{
    public const VERSION = '2026-11-09.1';

    public const MIN_SCORE = 1;

    public const MAX_SCORE = 5;

    public static function system(): string
    {
        return <<<'PROMPT'
You grade answers written by a support assistant for Ledgerly, an invoicing product.
You are given the customer's question, the help-center passages the assistant was allowed to use (possibly none), and the assistant's answer.
Grade the answer from 1 to 5:
5 - correct, complete, and every claim is supported by the passages
4 - correct but missing a detail, or slightly padded
3 - partly correct, or correct but not supported by the passages
2 - mostly wrong, or contradicts the passages
1 - wrong, invented, or ignores the question
If no passages were provided, the right answer is to say it cannot find that and offer a support ticket; grade that 5 and any invented answer 1.
Reply with a JSON object: {"score": <1-5>, "reason": "<one short sentence>"}.
PROMPT;
    }

    public static function messages(string $question, string $passages, string $answer): array
    {
        $passages = $passages === ''
            ? '(none provided)'
            : $passages;

        return [
            [
                'role' => 'system',
                'content' => self::system(),
            ],
            [
                'role' => 'user',
                'content' => "Question:\n{$question}\n\nPassages:\n{$passages}\n\nAnswer:\n{$answer}",
            ],
        ];
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'score' => [
                    'type' => 'integer',
                    'enum' => range(self::MIN_SCORE, self::MAX_SCORE),
                ],
                'reason' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['score', 'reason'],
            'additionalProperties' => false,
        ];
    }
}
