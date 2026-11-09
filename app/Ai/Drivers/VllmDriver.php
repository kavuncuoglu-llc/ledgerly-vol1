<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Drivers\Errors\VllmErrors;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Tokens\TokenCounter;
use Generator;

final class VllmDriver implements ChatModel
{
    private readonly OpenAiCompatibleDriver $compatible;

    public function __construct(
        array $config,
        TokenCounter $counter,
        DebugLog $debug,
        ?OpenAiCompatibleDriver $compatible = null,
    ) {
        $this->compatible = $compatible ?? new OpenAiCompatibleDriver(
            config: $config,
            counter: $counter,
            debug: $debug,
            client: new ProviderClient(
                config: $config,
                errors: new VllmErrors,
                headers: ['Authorization' => 'Bearer '.($config['api_key'] ?? '')],
            ),
        );
    }

    public function name(): string
    {
        return $this->compatible->name();
    }

    public function supports(Capability $capability): bool
    {
        return $this->compatible->supports($capability);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->compatible->chat($request);
    }

    public function stream(ChatRequest $request): Generator
    {
        return yield from $this->compatible->stream($request);
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return $this->compatible->raw($method, $path, $body);
    }
}
