<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\UnscriptedModelCall;

final class FakeLedger
{
    public const ANY_FEATURE = '*';

    private array $scripts = [];

    private array $sent = [];

    public function script(string $feature, ScriptedReply $reply, int $times = 1): void
    {
        for ($count = 0; $count < $times; $count++) {
            $this->scripts[$feature][] = $reply;
        }
    }

    public function record(string $provider, ChatRequest $request): void
    {
        $this->sent[] = [
            'provider' => $provider,
            'request' => $request,
        ];
    }

    public function pull(string $provider, ChatRequest $request): ScriptedReply
    {
        foreach ([$request->feature, self::ANY_FEATURE] as $queue) {
            if (! empty($this->scripts[$queue])) {
                return array_shift($this->scripts[$queue]);
            }
        }

        throw UnscriptedModelCall::for($provider, $request->feature);
    }

    public function sent(): array
    {
        return $this->sent;
    }

    public function sentTo(string $provider): array
    {
        return array_values(array_filter(
            $this->sent,
            fn (array $entry): bool => $entry['provider'] === $provider,
        ));
    }

    public function sentFor(string $feature): array
    {
        return array_values(array_filter(
            $this->sent,
            fn (array $entry): bool => $entry['request']->feature === $feature,
        ));
    }
}
