<?php

namespace App\Ai\Profile;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use Closure;

final class ProfileRunner
{
    public const FEATURE = 'profile';

    public function __construct(private readonly AiManager $ai) {}

    public function run(string $provider, array $prompts, int $maxTokens, bool $coldStart, ?Closure $progress = null): array
    {
        $model = $this->ai->chat($provider);

        $turns = [];

        foreach ($prompts as $index => $prompt) {
            $turn = $this->turn($model, $prompt, $index, $coldStart && $index === 0, $maxTokens);

            $turns[] = $turn;

            if ($progress !== null) {
                $progress($turn);
            }

            if ($turn->error !== '') {
                break;
            }
        }

        return $turns;
    }

    private function turn(ChatModel $model, string $prompt, int $index, bool $cold, int $maxTokens): ProfileTurn
    {
        $startedAt = microtime(true);

        $firstTokenAt = 0.0;

        $deltaTimes = [];

        try {
            $events = $model->stream($this->request($prompt, $maxTokens));

            foreach ($events as $event) {
                if (! $event instanceof Delta) {
                    continue;
                }

                $deltaTimes[] = microtime(true);

                $firstTokenAt = $firstTokenAt ?: $deltaTimes[0];
            }

            $usage = $events->getReturn()->usage;
        } catch (ModelRequestFailed|StreamTruncated $exception) {
            return new ProfileTurn(
                index: $index,
                cold: $cold,
                startedAt: $startedAt,
                firstTokenAt: $firstTokenAt,
                endedAt: microtime(true),
                promptTokens: 0,
                completionTokens: 0,
                deltaTimes: $deltaTimes,
                error: $exception->getMessage(),
            );
        }

        return new ProfileTurn(
            index: $index,
            cold: $cold,
            startedAt: $startedAt,
            firstTokenAt: $firstTokenAt,
            endedAt: microtime(true),
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            deltaTimes: $deltaTimes,
        );
    }

    private function request(string $prompt, int $maxTokens): ChatRequest
    {
        return ChatRequest::fromOptions(
            messages: [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            options: [
                'profile' => 'deterministic',
                'max_tokens' => $maxTokens,
            ],
            feature: self::FEATURE,
        );
    }
}
