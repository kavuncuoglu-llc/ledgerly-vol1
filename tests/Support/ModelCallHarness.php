<?php

namespace Tests\Support;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Http\CallDetails;
use Generator;

final class ModelCallHarness
{
    public function chat(
        array $messages,
        array $options = [],
        string $feature = 'unknown',
        ?string $promptVersion = null,
        bool $repaired = false,
        ?int $conversationId = null,
        ?CallDetails $details = null,
    ): ChatResponse {
        return app(AiManager::class)->for($feature)->chat(
            ChatRequest::fromOptions($messages, $options, $feature, $promptVersion, $repaired, $conversationId, $details),
        );
    }

    public function stream(
        array $messages,
        array $options = [],
        string $feature = 'unknown',
        ?string $promptVersion = null,
        ?int $conversationId = null,
        ?CallDetails $details = null,
    ): Generator {
        return app(AiManager::class)->for($feature)->stream(
            ChatRequest::fromOptions($messages, $options, $feature, $promptVersion, false, $conversationId, $details),
        );
    }

    public static function deltas(Generator $stream): array
    {
        $texts = [];

        foreach ($stream as $event) {
            if ($event instanceof Delta) {
                $texts[] = $event->text;
            }
        }

        return $texts;
    }
}
