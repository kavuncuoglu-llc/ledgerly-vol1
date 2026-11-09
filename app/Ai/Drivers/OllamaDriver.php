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
use App\Ai\Drivers\Support\NdjsonReader;
use App\Ai\Drivers\Support\OllamaMessages;
use App\Ai\Drivers\Support\OptionMapper;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Drivers\Support\StreamAccumulator;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Native\OllamaEndpoint;
use App\Ai\Tokens\TokenCounter;
use Generator;
use RuntimeException;
use stdClass;

final class OllamaDriver implements ChatModel, SupportsContextWindowOverride
{
    private const FINISH_REASONS = [
        'stop' => FinishReason::Stop,
        'load' => FinishReason::Stop,
        'length' => FinishReason::Length,
    ];

    private const RENAMED_OPTIONS = [
        'max_tokens' => 'num_predict',
        'max_completion_tokens' => 'num_predict',
    ];

    private const SUPPORTED_OPTIONS = [
        'temperature',
        'top_p',
        'top_k',
        'min_p',
        'typical_p',
        'seed',
        'repeat_penalty',
        'repeat_last_n',
        'presence_penalty',
        'frequency_penalty',
        'num_ctx',
        'num_predict',
        'num_keep',
        'num_batch',
        'num_gpu',
        'stop',
    ];

    private ?int $contextWindow = null;

    private readonly ProviderClient $client;

    public function __construct(
        private readonly array $config,
        private readonly TokenCounter $counter,
        private readonly DebugLog $debug,
        ?ProviderClient $client = null,
    ) {
        $this->client = $client ?? new ProviderClient(
            config: [
                ...$config,
                'base_url' => OllamaEndpoint::nativeBaseUrl((string) ($config['base_url'] ?? '')),
            ],
            errors: new OpenAiErrors,
            headers: ['Authorization' => 'Bearer '.($config['api_key'] ?? '')],
        );
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
            Capability::CacheBreakpoints, Capability::Reasoning => false,
        };
    }

    public function withContextWindow(int $tokens): static
    {
        $clone = clone $this;

        $clone->contextWindow = $tokens;

        return $clone;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $body = [
            ...$this->body($request),
            'stream' => false,
        ];

        $response = $this->client->post('/api/chat', $body);

        $this->logExchange($request, $body, $response->json());

        return $this->toResponse($response->json() ?? []);
    }

    public function stream(ChatRequest $request): Generator
    {
        $body = [
            ...$this->body($request),
            'stream' => true,
        ];

        $response = $this->client->post('/api/chat', $body, stream: true);

        $accumulator = new StreamAccumulator;

        $final = [];

        $interruption = null;

        try {
            $toolIndex = 0;

            foreach (NdjsonReader::lines($response->toPsrResponse()->getBody()) as $line) {
                $chunk = $this->decodeChunk($line);

                $accumulator->identify('', (string) ($chunk['model'] ?? ''));

                $accumulator->text((string) ($chunk['message']['content'] ?? ''));

                foreach ($this->toolCallsOf($chunk['message']['tool_calls'] ?? [], $toolIndex) as $call) {
                    $accumulator->toolCall($call['index'], $call['id'], $call['name'], $call['arguments']);
                }

                $toolIndex += count($chunk['message']['tool_calls'] ?? []);

                yield from $accumulator->drain();

                if ($chunk['done'] ?? false) {
                    $final = $chunk;

                    $accumulator->markDone();

                    break;
                }
            }
        } catch (ModelRequestFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $interruption = $exception;
        }

        $this->settle($accumulator, $final);

        $fallbackModel = (string) ($body['model'] ?? '');

        $estimate = $this->estimatedUsage($request, $accumulator);

        $completed = $accumulator
            ->toResponse($fallbackModel, $estimate)
            ->withFirstTokenMs($this->firstTokenMs($final));

        $this->logExchange($request, $body, [
            'content' => $completed->content,
            'tool_calls' => $completed->toolCalls,
            'finish_reason' => $completed->finishReason->value,
            'usage' => $completed->usage->toArray(),
            'timings' => $this->timingsOf($final),
        ]);

        if (! $accumulator->isDone()) {
            yield new Failed(
                reason: $interruption?->getMessage() ?? 'The stream ended before the final message.',
                partial: $accumulator->toResponse($fallbackModel, $estimate, FinishReason::Truncated),
            );

            throw new StreamTruncated($accumulator->content(), $accumulator->finishReason()?->value, $interruption);
        }

        yield new Completed($completed);

        return $completed;
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        $response = $this->client->send($method, $this->rawPath($path), $body);

        return $response->json() ?? ['body' => $response->body()];
    }

    private function rawPath(string $path): string
    {
        if (str_starts_with($path, '/api/')) {
            return $path;
        }

        if (str_starts_with($path, '/v1/')) {
            return $path;
        }

        return '/v1'.$path;
    }

    private function body(ChatRequest $request): array
    {
        $body = [
            'model' => $request->model ?? $this->config['model'] ?? null,
            'messages' => OllamaMessages::translate($request->messages),
        ];

        $options = $this->options($request->options);

        if ($options !== []) {
            $body['options'] = $options;
        }

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        if ($request->toolChoice !== null) {
            WarnOnce::droppedOption($this->name(), 'tool_choice');
        }

        $format = $this->format($request);

        if ($format !== null) {
            $body['format'] = $format;
        }

        $keepAlive = $this->keepAlive();

        if ($keepAlive !== null) {
            $body['keep_alive'] = $keepAlive;
        }

        return $body;
    }

    private function options(array $options): array
    {
        $unsupported = array_values(array_filter(
            array_keys($options),
            fn (string|int $name): bool => ! in_array(self::RENAMED_OPTIONS[$name] ?? $name, self::SUPPORTED_OPTIONS, true),
        ));

        $mapped = (new OptionMapper(
            provider: $this->name(),
            renames: self::RENAMED_OPTIONS,
            dropped: $unsupported,
        ))->map($options);

        if (is_string($mapped['stop'] ?? null)) {
            $mapped['stop'] = [$mapped['stop']];
        }

        $numCtx = $this->contextWindow ?? config('ai.ollama.num_ctx');

        if (filled($numCtx)) {
            $mapped['num_ctx'] = (int) $numCtx;
        }

        return $mapped;
    }

    private function format(ChatRequest $request): string|array|null
    {
        if ($request->responseFormat === null) {
            return null;
        }

        $schema = $request->responseFormat['json_schema']['schema'] ?? null;

        if (is_array($schema)) {
            return $schema;
        }

        return 'json';
    }

    private function keepAlive(): string|int|null
    {
        $keepAlive = $this->config['keep_alive'] ?? config('ai.ollama.keep_alive');

        if (blank($keepAlive)) {
            return null;
        }

        if (is_numeric($keepAlive)) {
            return (int) $keepAlive;
        }

        return (string) $keepAlive;
    }

    private function toResponse(array $body): ChatResponse
    {
        $message = $body['message'] ?? null;

        if (! is_array($message)) {
            throw ModelRequestFailed::malformed('message');
        }

        $toolCalls = $this->openAiToolCalls($message['tool_calls'] ?? []);

        $content = (string) ($message['content'] ?? '');

        return new ChatResponse(
            id: '',
            model: (string) ($body['model'] ?? ''),
            content: $toolCalls !== [] && $content === ''
                ? null
                : $content,
            toolCalls: $toolCalls,
            finishReason: $this->finishReasonOf($body, $toolCalls !== []),
            usage: $this->usageOf($body),
            raw: $body,
            firstTokenMs: $this->firstTokenMs($body),
        );
    }

    private function finishReasonOf(array $body, bool $hasToolCalls): FinishReason
    {
        if ($hasToolCalls) {
            return FinishReason::ToolCalls;
        }

        return FinishReason::normalize($body['done_reason'] ?? null, self::FINISH_REASONS, $this->name());
    }

    private function usageOf(array $body): Usage
    {
        $cached = $body['prompt_eval_cached_count'] ?? null;

        return new Usage(
            promptTokens: (int) ($body['prompt_eval_count'] ?? 0),
            completionTokens: (int) ($body['eval_count'] ?? 0),
            cachedTokens: $cached === null
                ? null
                : (int) $cached,
        );
    }

    private function firstTokenMs(array $body): ?int
    {
        if (! isset($body['load_duration'])) {
            return null;
        }

        $evalCount = (int) ($body['eval_count'] ?? 0);

        $oneToken = $evalCount > 0
            ? (int) ($body['eval_duration'] ?? 0) / $evalCount
            : 0;

        $nanoseconds = (int) $body['load_duration'] + (int) ($body['prompt_eval_duration'] ?? 0) + $oneToken;

        return (int) round($nanoseconds / 1_000_000);
    }

    private function timingsOf(array $body): array
    {
        return array_intersect_key($body, array_flip([
            'total_duration',
            'load_duration',
            'prompt_eval_count',
            'prompt_eval_cached_count',
            'prompt_eval_duration',
            'eval_count',
            'eval_duration',
        ]));
    }

    private function openAiToolCalls(array $calls): array
    {
        return array_map(
            fn (array $call): array => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                ],
            ],
            $this->toolCallsOf($calls, 0),
        );
    }

    private function toolCallsOf(array $calls, int $firstIndex): array
    {
        $out = [];

        foreach (array_values($calls) as $offset => $call) {
            $index = $firstIndex + $offset;

            $arguments = $call['function']['arguments'] ?? [];

            $out[] = [
                'index' => $index,
                'id' => (string) ($call['id'] ?? 'call_'.$index),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) && $arguments !== []
                    ? (string) json_encode($arguments)
                    : (string) json_encode(new stdClass),
            ];
        }

        return $out;
    }

    private function settle(StreamAccumulator $accumulator, array $final): void
    {
        if ($final === []) {
            return;
        }

        $accumulator->reportUsage($this->usageOf($final));

        $accumulator->finish($this->finishReasonOf($final, $accumulator->toolCalls() !== []));
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

        $this->debug->write($this->name(), $request->feature, $request->conversationId, '/api/chat', $body, $response);
    }

    private function decodeChunk(string $line): array
    {
        $chunk = json_decode($line, true);

        if (! is_array($chunk)) {
            throw ModelRequestFailed::malformedStreamChunk($line);
        }

        if (isset($chunk['error'])) {
            throw ModelRequestFailed::rejected(500, (string) $chunk['error']);
        }

        return $chunk;
    }
}
