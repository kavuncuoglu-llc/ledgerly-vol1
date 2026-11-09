<?php

namespace App\Ai\Conversations;

use App\Ai\Exceptions\UnreadableReplayScript;

final readonly class ReplayScript
{
    public function __construct(
        public array $messages,
        public string $followUp = '',
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw UnreadableReplayScript::fileMissing($path);
        }

        $script = json_decode((string) file_get_contents($path), true);

        $messages = is_array($script)
            ? ($script['messages'] ?? null)
            : null;

        if (! is_array($messages)) {
            throw UnreadableReplayScript::notAMessageList($path);
        }

        if ($messages === []) {
            throw UnreadableReplayScript::notAMessageList($path);
        }

        foreach ($messages as $message) {
            if (! is_string($message)) {
                throw UnreadableReplayScript::notAMessageList($path);
            }
        }

        return new self(
            messages: array_values($messages),
            followUp: (string) ($script['followUp'] ?? ''),
        );
    }
}
