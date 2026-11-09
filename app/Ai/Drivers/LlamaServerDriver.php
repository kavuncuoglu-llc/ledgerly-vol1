<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Usage;
use App\Ai\Drivers\Errors\LlamaServerErrors;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\OptionMapper;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Native\LlamaEndpoint;
use App\Ai\Tokens\TokenCounter;
use Generator;

final class LlamaServerDriver implements ChatModel, SupportsGrammar
{
    private const STOP_TYPES = [
        'eos' => FinishReason::Stop,
        'word' => FinishReason::Stop,
        'limit' => FinishReason::Length,
    ];

    private readonly OpenAiCompatibleDriver $compatible;

    private readonly ProviderClient $native;

    private string $grammar = '';

    public function __construct(
        private readonly array $config,
        TokenCounter $counter,
        private readonly DebugLog $debug,
        ?OpenAiCompatibleDriver $compatible = null,
        ?ProviderClient $native = null,
    ) {
        $this->compatible = $compatible ?? new OpenAiCompatibleDriver(
            config: $config,
            counter: $counter,
            debug: $debug,
            client: new ProviderClient(
                config: $config,
                errors: new LlamaServerErrors,
                headers: $this->headers(),
            ),
        );

        $this->native = $native ?? new ProviderClient(
            config: [
                ...$config,
                'base_url' => LlamaEndpoint::nativeBaseUrl((string) ($config['base_url'] ?? '')),
            ],
            errors: new LlamaServerErrors,
            headers: $this->headers(),
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

    public function withGrammar(string $grammar): static
    {
        $clone = clone $this;

        $clone->grammar = $grammar;

        return $clone;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        if ($this->grammar !== '') {
            return $this->constrainedChat($request);
        }

        $response = $this->compatible->chat($request);

        return $response->withFirstTokenMs($this->firstTokenMs((array) ($response->raw['timings'] ?? [])));
    }

    public function stream(ChatRequest $request): Generator
    {
        if ($this->grammar === '') {
            return yield from $this->compatible->stream($request);
        }

        $response = $this->constrainedChat($request);

        if ($response->content !== '') {
            yield new Delta((string) $response->content);
        }

        yield new Completed($response);

        return $response;
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return $this->compatible->raw($method, $path, $body);
    }

    private function constrainedChat(ChatRequest $request): ChatResponse
    {
        $this->assertCanBeConstrained($request);

        $body = [
            ...$this->completionOptions($request),
            'prompt' => $this->renderedPrompt($request),
            'grammar' => $this->grammar,
            'stream' => false,
            'cache_prompt' => true,
        ];

        $reply = $this->native->post('/completion', $body)->json();

        $this->logExchange($request, '/completion', $body, $reply);

        return $this->toResponse((array) $reply);
    }

    private function assertCanBeConstrained(ChatRequest $request): void
    {
        if ($request->tools !== []) {
            throw new InvalidRequest('A grammar-constrained completion cannot carry tools: the grammar is the only thing the model may emit.');
        }

        if ($request->responseFormat !== null) {
            throw new InvalidRequest('A grammar-constrained completion cannot also carry a response_format: pick the grammar or the schema.');
        }
    }

    private function completionOptions(ChatRequest $request): array
    {
        return (new OptionMapper(
            provider: $this->name(),
            renames: [
                'max_tokens' => 'n_predict',
            ],
        ))->map($request->options);
    }

    private function renderedPrompt(ChatRequest $request): string
    {
        $applied = $this->native->post('/apply-template', [
            'messages' => $request->messages,
        ])->json('prompt');

        if (! is_string($applied)) {
            throw ModelRequestFailed::malformed('prompt');
        }

        return $applied;
    }

    private function toResponse(array $body): ChatResponse
    {
        if (! is_string($body['content'] ?? null)) {
            throw ModelRequestFailed::malformed('content');
        }

        $timings = (array) ($body['timings'] ?? []);

        return new ChatResponse(
            id: '',
            model: (string) ($body['model'] ?? $this->config['model'] ?? ''),
            content: $body['content'],
            toolCalls: [],
            finishReason: FinishReason::normalize($body['stop_type'] ?? null, self::STOP_TYPES, $this->name()),
            usage: new Usage(
                promptTokens: (int) ($body['tokens_evaluated'] ?? 0),
                completionTokens: (int) ($body['tokens_predicted'] ?? 0),
                cachedTokens: isset($body['tokens_cached']) ? (int) $body['tokens_cached'] : null,
            ),
            raw: $body,
            firstTokenMs: $this->firstTokenMs($timings),
        );
    }

    private function firstTokenMs(array $timings): ?int
    {
        if (! isset($timings['prompt_ms'])) {
            return null;
        }

        return (int) round((float) $timings['prompt_ms'] + (float) ($timings['predicted_per_token_ms'] ?? 0));
    }

    private function logExchange(ChatRequest $request, string $path, array $body, mixed $response): void
    {
        if (! $this->debug->enabled($request->feature, $request->conversationId)) {
            return;
        }

        $this->debug->write($this->name(), $request->feature, $request->conversationId, $path, $body, $response);
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.($this->config['api_key'] ?? ''),
        ];
    }
}
