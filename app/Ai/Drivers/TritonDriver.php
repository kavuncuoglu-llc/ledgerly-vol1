<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Drivers\Errors\OpenAiErrors;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Native\TritonEndpoint;
use App\Ai\Tokens\TokenCounter;
use Generator;

final class TritonDriver implements ChatModel
{
    private const DEFAULT_MAX_TOKENS = 512;

    private readonly OpenAiCompatibleDriver $compatible;

    private readonly int $defaultMaxTokens;

    public function __construct(
        array $config,
        TokenCounter $counter,
        DebugLog $debug,
        ?OpenAiCompatibleDriver $compatible = null,
    ) {
        $this->defaultMaxTokens = (int) ($config['default_max_tokens'] ?? self::DEFAULT_MAX_TOKENS);

        $this->compatible = $compatible ?? new OpenAiCompatibleDriver(
            config: $config,
            counter: $counter,
            debug: $debug,
            client: new ProviderClient(
                config: $config,
                errors: new OpenAiErrors,
                headers: TritonEndpoint::authHeaders($config),
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
        return $this->compatible->chat($this->withTokenLimit($request));
    }

    public function stream(ChatRequest $request): Generator
    {
        return yield from $this->compatible->stream($this->withTokenLimit($request));
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return $this->compatible->raw($method, $path, $body);
    }

    private function withTokenLimit(ChatRequest $request): ChatRequest
    {
        if (isset($request->options['max_tokens'])) {
            return $request;
        }

        if (isset($request->options['max_completion_tokens'])) {
            return $request;
        }

        return $request->withOptions([
            ...$request->options,
            'max_tokens' => $this->defaultMaxTokens,
        ]);
    }
}
