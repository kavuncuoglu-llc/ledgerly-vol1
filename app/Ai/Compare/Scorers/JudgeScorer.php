<?php

namespace App\Ai\Compare\Scorers;

use App\Ai\Compare\Score;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Prompts\JudgePrompt;

final class JudgeScorer implements Scorer
{
    public const FEATURE = 'compare.judge';

    public function __construct(
        private readonly ChatModel $judge,
        private readonly string $question,
        private readonly string $passages,
    ) {}

    public function name(): string
    {
        return 'judge';
    }

    public function score(ChatResponse $response): Score
    {
        $verdict = $this->judge->chat($this->request((string) $response->content));

        $decoded = $this->decode((string) $verdict->content);

        $grade = $decoded['score'] ?? null;

        if (! is_int($grade)) {
            return new Score($this->name(), 0.0, 'the judge did not return a score');
        }

        $grade = max(JudgePrompt::MIN_SCORE, min(JudgePrompt::MAX_SCORE, $grade));

        return new Score(
            $this->name(),
            ($grade - JudgePrompt::MIN_SCORE) / (JudgePrompt::MAX_SCORE - JudgePrompt::MIN_SCORE),
            "{$grade}/".JudgePrompt::MAX_SCORE.' '.(string) ($decoded['reason'] ?? ''),
        );
    }

    private function request(string $answer): ChatRequest
    {
        $request = new ChatRequest(
            messages: JudgePrompt::messages($this->question, $this->passages, $answer),
            feature: self::FEATURE,
            options: ['max_tokens' => (int) config('ai.compare.judge_max_tokens')],
            profile: 'deterministic',
            promptVersion: JudgePrompt::VERSION,
        );

        if (! $this->judge->supports(Capability::StructuredOutput)) {
            return $request;
        }

        return new ChatRequest(
            messages: $request->messages,
            feature: $request->feature,
            options: $request->options,
            responseFormat: [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'verdict',
                    'strict' => true,
                    'schema' => JudgePrompt::schema(),
                ],
            ],
            profile: $request->profile,
            promptVersion: $request->promptVersion,
        );
    }

    private function decode(string $content): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $match) !== 1) {
            return [];
        }

        $decoded = json_decode($match[0], true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }
}
