<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Failed;
use App\Ai\Contracts\Usage;
use App\Ai\Drivers\Errors\OpenAiErrors;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\OptionMapper;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Drivers\Support\SseReader;
use App\Ai\Drivers\Support\StreamAccumulator;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Native\OllamaEndpoint;
use App\Ai\Tokens\TokenCounter;
use Generator;
use RuntimeException;

final class OpenAiCompatibleDriver implements ChatModel, SupportsContextWindowOverride
{
    private const FINISH_REASONS = [
        'stop' => FinishReason::Stop,
        'done' => FinishReason::Stop,
        'length' => FinishReason::Length,
        'tool_calls' => FinishReason::ToolCalls,
        'function_call' => FinishReason::ToolCalls,
        'content_filter' => FinishReason::ContentFilter,
    ];

    private const HOSTED_OPENAI_UNSUPPORTED_OPTIONS = ['min_p', 'top_k', 'repeat_penalty'];

    private array $extraOptions = [];

    private readonly ProviderClient $client;

    private ?OllamaDriver $native = null;

    public function __construct(
        private readonly array $config,
        private readonly TokenCounter $counter,
        private readonly DebugLog $debug,
        ?ProviderClient $client = null,
    ) {
        $this->client = $client ?? new ProviderClient(
            config: $config,
            errors: new OpenAiErrors,
            headers: ['Authorization' => 'Bearer '.($config['api_key'] ?? '')],
        );

        if (OllamaEndpoint::wantsNative($config)) {
            $this->native = $this->nativeDriver();
        }
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function supports(Capability $capability): bool
    {
        $declared = $this->config['capabilities'] ?? null;

        if (is_array($declared)) {
            return in_array($capability->value, $declared, true);
        }

        return match ($capability) {
            Capability::Tools, Capability::StructuredOutput, Capability::Seed => true,
            Capability::Vision => (bool) ($this->config['vision'] ?? false),
            Capability::CacheBreakpoints => (bool) ($this->config['cache_breakpoints'] ?? false),
            Capability::Reasoning => (bool) ($this->config['reasoning'] ?? false),
        };
    }

    public function withContextWindow(int $tokens): static
    {
        $clone = clone $this;

        if (OllamaEndpoint::isOllama($this->config)) {
            $clone->native = ($this->native ?? $this->nativeDriver())->withContextWindow($tokens);

            return $clone;
        }

        $clone->extraOptions['num_ctx'] = $tokens;

        return $clone;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        if ($this->native !== null) {
            return $this->native->chat($request);
        }

        $body = [
            ...$this->body($request),
            'stream' => false,
        ];

        $response = $this->client->post('/chat/completions', $body);

        $this->logExchange($request, $body, $response->json());

        return $this->toResponse($response->json() ?? []);
    }

    public function stream(ChatRequest $request): Generator
    {
        if ($this->native !== null) {
            return yield from $this->native->stream($request);
        }

        $body = [
            ...$this->body($request),
            'stream' => true,
            'stream_options' => [
                'include_usage' => true,
            ],
        ];

        $response = $this->client->post('/chat/completions', $body, stream: true);

        $accumulator = new StreamAccumulator;

        $interruption = null;

        try {
            foreach (SseReader::payloads($response->toPsrResponse()->getBody()) as $payload) {
                if ($payload === '[DONE]') {
                    $accumulator->markDone();

                    break;
                }

                $this->absorb($accumulator, $this->decodeChunk($payload));

                yield from $accumulator->drain();
            }
        } catch (ModelRequestFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $interruption = $exception;
        }

        $fallbackModel = (string) ($body['model'] ?? '');

        $estimate = $this->estimatedUsage($request, $accumulator);

        $completed = $accumulator->toResponse($fallbackModel, $estimate);

        $this->logExchange($request, $body, [
            'content' => $completed->content,
            'tool_calls' => $completed->toolCalls,
            'finish_reason' => $completed->finishReason->value,
            'usage' => $completed->usage->toArray(),
        ]);

        if (! $accumulator->isDone()) {
            yield new Failed(
                reason: $interruption?->getMessage() ?? 'The stream ended before [DONE].',
                partial: $accumulator->toResponse($fallbackModel, $estimate, FinishReason::Truncated),
            );

            throw new StreamTruncated($accumulator->content(), $accumulator->finishReason()?->value, $interruption);
        }

        yield new Completed($completed);

        return $completed;
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        $response = $this->client->send($method, $path, $body);

        return $response->json() ?? ['body' => $response->body()];
    }

    private function nativeDriver(): OllamaDriver
    {
        return new OllamaDriver($this->config, $this->counter, $this->debug);
    }

    private function body(ChatRequest $request): array
    {
        $body = [
            ...$this->options($request->options),
            ...$this->extraOptions,
            'model' => $request->model ?? $this->config['model'] ?? null,
            'messages' => $this->messages($request),
        ];

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        if ($request->toolChoice !== null) {
            $body['tool_choice'] = $request->toolChoice;
        }

        if ($request->responseFormat !== null) {
            $body['response_format'] = $request->responseFormat;
        }

        return $body;
    }

    private function options(array $options): array
    {
        return (new OptionMapper(
            provider: $this->name(),
            renames: (array) ($this->config['option_renames'] ?? []),
            dropped: $this->unsupportedOptions(),
        ))->map($options);
    }

    private function unsupportedOptions(): array
    {
        if (isset($this->config['unsupported_options'])) {
            return (array) $this->config['unsupported_options'];
        }

        if (str_contains((string) ($this->config['base_url'] ?? ''), 'api.openai.com')) {
            return self::HOSTED_OPENAI_UNSUPPORTED_OPTIONS;
        }

        return [];
    }

    private function messages(ChatRequest $request): array
    {
        if ($request->cacheBreakpointsAfter === []) {
            return $request->messages;
        }

        if (! $this->supports(Capability::CacheBreakpoints)) {
            return $request->messages;
        }

        $messages = $request->messages;

        foreach ($request->cacheBreakpointsAfter as $index) {
            if (isset($messages[$index])) {
                $messages[$index]['cache_control'] = [
                    'type' => 'ephemeral',
                ];
            }
        }

        return $messages;
    }

    private function toResponse(array $body): ChatResponse
    {
        $message = $body['choices'][0]['message'] ?? [];

        $content = $message['content'] ?? null;

        $toolCalls = $message['tool_calls'] ?? [];

        if ($content === null && $toolCalls === []) {
            throw ModelRequestFailed::malformed();
        }

        return new ChatResponse(
            id: (string) ($body['id'] ?? ''),
            model: (string) ($body['model'] ?? ''),
            content: $content,
            toolCalls: $toolCalls,
            finishReason: FinishReason::normalize($body['choices'][0]['finish_reason'] ?? null, self::FINISH_REASONS, $this->name()),
            usage: $this->usageOf($body['usage'] ?? []),
            raw: $body,
        );
    }

    private function usageOf(array $usage): Usage
    {
        $cached = $usage['prompt_tokens_details']['cached_tokens'] ?? null;

        $reasoning = $usage['completion_tokens_details']['reasoning_tokens'] ?? null;

        return new Usage(
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            cachedTokens: $cached === null ? null : (int) $cached,
            reasoningTokens: $reasoning === null ? null : (int) $reasoning,
        );
    }

    private function absorb(StreamAccumulator $accumulator, array $chunk): void
    {
        $accumulator->identify((string) ($chunk['id'] ?? ''), (string) ($chunk['model'] ?? ''));

        $reason = $chunk['choices'][0]['finish_reason'] ?? null;

        if ($reason !== null) {
            $accumulator->finish(FinishReason::normalize($reason, self::FINISH_REASONS, $this->name()));
        }

        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $accumulator->reportUsage($this->usageOf($chunk['usage']));
        }

        $accumulator->text((string) ($chunk['choices'][0]['delta']['content'] ?? ''));

        foreach ($chunk['choices'][0]['delta']['tool_calls'] ?? [] as $fragment) {
            $accumulator->toolCall(
                index: (int) ($fragment['index'] ?? 0),
                id: $fragment['id'] ?? null,
                name: $fragment['function']['name'] ?? null,
                arguments: (string) ($fragment['function']['arguments'] ?? ''),
            );
        }
    }

    private function estimatedUsage(ChatRequest $request, StreamAccumulator $accumulator): ?Usage
    {
        if ($accumulator->hasUsage()) {
            return null;
        }

        return new Usage(
            promptTokens: $this->counter->count((string) json_encode($request->messages)),
            completionTokens: $this->counter->count($accumulator->content()),
            estimated: true,
        );
    }

    private function logExchange(ChatRequest $request, array $body, mixed $response): void
    {
        if (! $this->debug->enabled($request->feature, $request->conversationId)) {
            return;
        }

        $this->debug->write($this->name(), $request->feature, $request->conversationId, '/chat/completions', $body, $response);
    }

    private function decodeChunk(string $payload): array
    {
        $chunk = json_decode($payload, true);

        if (! is_array($chunk)) {
            throw ModelRequestFailed::malformedStreamChunk($payload);
        }

        return $chunk;
    }
}
