<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capabilities\SupportsNativeStructuredOutput;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Failed;
use App\Ai\Contracts\Usage;
use App\Ai\Drivers\Errors\AnthropicErrors;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\OptionMapper;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Drivers\Support\SseReader;
use App\Ai\Drivers\Support\StreamAccumulator;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\StreamTruncated;
use Generator;
use RuntimeException;

final class AnthropicDriver implements ChatModel, SupportsNativeStructuredOutput
{
    private const FINISH_REASONS = [
        'end_turn' => FinishReason::Stop,
        'stop_sequence' => FinishReason::Stop,
        'max_tokens' => FinishReason::Length,
        'tool_use' => FinishReason::ToolCalls,
        'refusal' => FinishReason::ContentFilter,
    ];

    private const UNSUPPORTED_OPTIONS = ['seed', 'min_p', 'frequency_penalty', 'presence_penalty', 'repeat_penalty'];

    private const DEFAULT_MAX_TOKENS = 1024;

    private bool $nativeStructuredOutput;

    private readonly ProviderClient $client;

    public function __construct(
        private readonly array $config,
        private readonly DebugLog $debug,
        ?ProviderClient $client = null,
    ) {
        $this->nativeStructuredOutput = (bool) ($config['native_structured_output'] ?? true);

        $this->client = $client ?? new ProviderClient(
            config: $config,
            errors: new AnthropicErrors,
            headers: [
                'x-api-key' => (string) ($config['api_key'] ?? ''),
                'anthropic-version' => (string) ($config['version'] ?? '2023-06-01'),
            ],
        );
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Tools, Capability::StructuredOutput, Capability::Vision, Capability::CacheBreakpoints => true,
            Capability::Seed => false,
            Capability::Reasoning => (bool) ($this->config['reasoning'] ?? false),
        };
    }

    public function withNativeStructuredOutput(bool $enabled = true): static
    {
        $clone = clone $this;

        $clone->nativeStructuredOutput = $enabled;

        return $clone;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $body = $this->body($request);

        $response = $this->client->post('/v1/messages', $body);

        $this->logExchange($request, $body, $response->json());

        return $this->toResponse($response->json() ?? [], $this->structuredToolName($request));
    }

    public function stream(ChatRequest $request): Generator
    {
        $body = [
            ...$this->body($request),
            'stream' => true,
        ];

        $response = $this->client->post('/v1/messages', $body, stream: true);

        $structuredTool = $this->structuredToolName($request);

        $accumulator = new StreamAccumulator;

        $state = [
            'prompt' => 0,
            'cached' => null,
            'created' => 0,
            'completion' => 0,
            'toolIndexes' => [],
            'structured' => [],
        ];

        $interruption = null;

        try {
            foreach (SseReader::payloads($response->toPsrResponse()->getBody()) as $payload) {
                $event = $this->decodeEvent($payload);

                $this->absorb($accumulator, $event, $state, $structuredTool);

                yield from $accumulator->drain();

                if ($accumulator->isDone()) {
                    break;
                }
            }
        } catch (ModelRequestFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $interruption = $exception;
        }

        $fallbackModel = (string) ($body['model'] ?? '');

        $accumulator->reportUsage($this->usageFrom($state));

        $completed = $accumulator->toResponse($fallbackModel);

        $this->logExchange($request, $body, [
            'content' => $completed->content,
            'tool_calls' => $completed->toolCalls,
            'finish_reason' => $completed->finishReason->value,
            'usage' => $completed->usage->toArray(),
        ]);

        if (! $accumulator->isDone()) {
            yield new Failed(
                reason: $interruption?->getMessage() ?? 'The stream ended before message_stop.',
                partial: $accumulator->toResponse($fallbackModel, null, FinishReason::Truncated),
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

    private function body(ChatRequest $request): array
    {
        $options = (new OptionMapper(
            provider: $this->name(),
            renames: ['stop' => 'stop_sequences'],
            dropped: self::UNSUPPORTED_OPTIONS,
        ))->map($request->options);

        $options['max_tokens'] ??= (int) ($this->config['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);

        if (isset($options['stop_sequences'])) {
            $options['stop_sequences'] = (array) $options['stop_sequences'];
        }

        $translated = $this->translateMessages($request);

        $body = [
            ...$options,
            'model' => $request->model ?? $this->config['model'] ?? null,
            'messages' => $translated['messages'],
        ];

        $system = $this->systemWithSchemaInstruction($translated['system'], $request);

        if ($system !== []) {
            $body['system'] = $system;
        }

        return [...$body, ...$this->toolFields($request)];
    }

    private function toolFields(ChatRequest $request): array
    {
        $tools = array_map(fn (array $tool): array => $this->translateTool($tool), $request->tools);

        $choice = $this->translateToolChoice($request->toolChoice);

        $structured = $this->structuredToolName($request);

        if ($structured !== null) {
            $tools[] = [
                'name' => $structured,
                'description' => 'Return the final answer by calling this tool with arguments that match the schema.',
                'input_schema' => $request->responseFormat['json_schema']['schema'] ?? ['type' => 'object'],
            ];

            $choice = [
                'type' => 'tool',
                'name' => $structured,
            ];
        }

        $fields = [];

        if ($tools !== []) {
            $fields['tools'] = $tools;
        }

        if ($choice !== null) {
            $fields['tool_choice'] = $choice;
        }

        return $fields;
    }

    private function structuredToolName(ChatRequest $request): ?string
    {
        if ($request->responseFormat === null) {
            return null;
        }

        if (! $this->nativeStructuredOutput) {
            return null;
        }

        if ($request->tools !== []) {
            return null;
        }

        return (string) ($request->responseFormat['json_schema']['name'] ?? 'respond');
    }

    private function systemWithSchemaInstruction(array $system, ChatRequest $request): array
    {
        if ($request->responseFormat === null) {
            return $system;
        }

        if ($this->structuredToolName($request) !== null) {
            return $system;
        }

        $schema = json_encode($request->responseFormat['json_schema']['schema'] ?? [], JSON_UNESCAPED_SLASHES);

        $system[] = [
            'type' => 'text',
            'text' => "Reply with a single JSON object and nothing else. It must satisfy this JSON Schema: {$schema}",
        ];

        return $system;
    }

    private function translateTool(array $tool): array
    {
        $function = $tool['function'] ?? $tool;

        return [
            'name' => $function['name'],
            'description' => $function['description'] ?? '',
            'input_schema' => $function['parameters'] ?? ['type' => 'object'],
        ];
    }

    private function translateToolChoice(string|array|null $choice): ?array
    {
        if ($choice === null) {
            return null;
        }

        if ($choice === 'auto') {
            return ['type' => 'auto'];
        }

        if ($choice === 'required') {
            return ['type' => 'any'];
        }

        if ($choice === 'none') {
            return ['type' => 'none'];
        }

        if (is_array($choice)) {
            return [
                'type' => 'tool',
                'name' => $choice['function']['name'] ?? $choice['name'] ?? '',
            ];
        }

        return null;
    }

    private function translateMessages(ChatRequest $request): array
    {
        $system = [];

        $messages = [];

        foreach ($request->messages as $index => $message) {
            $breakpoint = in_array($index, $request->cacheBreakpointsAfter, true);

            if (($message['role'] ?? '') === 'system') {
                $system[] = $this->withCacheControl(['type' => 'text', 'text' => $this->textOf($message['content'] ?? '')], $breakpoint);

                continue;
            }

            if (($message['role'] ?? '') === 'tool') {
                $messages = $this->appendToolResult($messages, $message);

                continue;
            }

            $messages[] = $this->translateMessage($message, $breakpoint);
        }

        return [
            'system' => $system,
            'messages' => $messages,
        ];
    }

    private function translateMessage(array $message, bool $breakpoint): array
    {
        $blocks = $this->blocksOf($message['content'] ?? '');

        foreach ($message['tool_calls'] ?? [] as $call) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => (string) ($call['id'] ?? ''),
                'name' => $call['function']['name'],
                'input' => $this->decodedArguments((string) ($call['function']['arguments'] ?? '')),
            ];
        }

        if ($breakpoint && $blocks !== []) {
            $last = array_key_last($blocks);

            $blocks[$last] = $this->withCacheControl($blocks[$last], true);
        }

        return [
            'role' => $message['role'],
            'content' => $blocks,
        ];
    }

    private function appendToolResult(array $messages, array $message): array
    {
        $block = [
            'type' => 'tool_result',
            'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
            'content' => $this->textOf($message['content'] ?? ''),
        ];

        $last = array_key_last($messages);

        if ($last !== null && $messages[$last]['role'] === 'user' && $this->endsWithToolResult($messages[$last])) {
            $messages[$last]['content'][] = $block;

            return $messages;
        }

        $messages[] = [
            'role' => 'user',
            'content' => [$block],
        ];

        return $messages;
    }

    private function endsWithToolResult(array $message): bool
    {
        $content = $message['content'];

        return end($content)['type'] === 'tool_result';
    }

    private function blocksOf(mixed $content): array
    {
        if (is_string($content)) {
            if ($content === '') {
                return [];
            }

            return [['type' => 'text', 'text' => $content]];
        }

        if (! is_array($content)) {
            return [];
        }

        return array_map(fn (array $part): array => $this->translatePart($part), $content);
    }

    private function translatePart(array $part): array
    {
        if (($part['type'] ?? '') !== 'image_url') {
            return $part;
        }

        $url = (string) ($part['image_url']['url'] ?? '');

        if (preg_match('#^data:(?<media>[^;]+);base64,(?<data>.+)$#s', $url, $matches) === 1) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $matches['media'],
                    'data' => $matches['data'],
                ],
            ];
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'url',
                'url' => $url,
            ],
        ];
    }

    private function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode("\n", array_map(
            fn (array $part): string => (string) ($part['text'] ?? ''),
            array_filter((array) $content, 'is_array'),
        ));
    }

    private function decodedArguments(string $arguments): array|object
    {
        $decoded = json_decode($arguments, true);

        if (! is_array($decoded) || $decoded === []) {
            return (object) [];
        }

        return $decoded;
    }

    private function withCacheControl(array $block, bool $breakpoint): array
    {
        if (! $breakpoint) {
            return $block;
        }

        $block['cache_control'] = [
            'type' => 'ephemeral',
        ];

        return $block;
    }

    private function toResponse(array $body, ?string $structuredTool): ChatResponse
    {
        $text = '';

        $toolCalls = [];

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = $this->normalizedToolCall($block);
            }
        }

        if ($text === '' && $toolCalls === [] && ! isset($body['content'])) {
            throw ModelRequestFailed::malformed('content');
        }

        $reason = FinishReason::normalize($body['stop_reason'] ?? null, self::FINISH_REASONS, $this->name());

        if ($structuredTool !== null && count($toolCalls) === 1 && $toolCalls[0]['function']['name'] === $structuredTool) {
            return $this->structuredResponse($body, $toolCalls[0]);
        }

        return new ChatResponse(
            id: (string) ($body['id'] ?? ''),
            model: (string) ($body['model'] ?? ''),
            content: $text === '' && $toolCalls !== [] ? null : $text,
            toolCalls: $toolCalls,
            finishReason: $reason,
            usage: $this->usageOfBody($body),
            raw: $body,
        );
    }

    private function structuredResponse(array $body, array $call): ChatResponse
    {
        return new ChatResponse(
            id: (string) ($body['id'] ?? ''),
            model: (string) ($body['model'] ?? ''),
            content: $call['function']['arguments'],
            toolCalls: [],
            finishReason: ($body['stop_reason'] ?? null) === 'max_tokens'
                ? FinishReason::Length
                : FinishReason::Stop,
            usage: $this->usageOfBody($body),
            raw: $body,
        );
    }

    private function normalizedToolCall(array $block): array
    {
        $input = $block['input'] ?? [];

        return [
            'id' => (string) $block['id'],
            'type' => 'function',
            'function' => [
                'name' => (string) $block['name'],
                'arguments' => $input === []
                    ? '{}'
                    : json_encode($input, JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function usageOfBody(array $body): Usage
    {
        return $this->usageFrom([
            'prompt' => (int) ($body['usage']['input_tokens'] ?? 0),
            'cached' => $body['usage']['cache_read_input_tokens'] ?? null,
            'created' => (int) ($body['usage']['cache_creation_input_tokens'] ?? 0),
            'completion' => (int) ($body['usage']['output_tokens'] ?? 0),
        ]);
    }

    private function usageFrom(array $state): Usage
    {
        $cached = $state['cached'] ?? null;

        $cachedTokens = $cached === null ? null : (int) $cached;

        return new Usage(
            promptTokens: (int) $state['prompt'] + (int) ($cachedTokens ?? 0) + (int) ($state['created'] ?? 0),
            completionTokens: (int) $state['completion'],
            cachedTokens: $cachedTokens,
        );
    }

    private function absorb(StreamAccumulator $accumulator, array $event, array &$state, ?string $structuredTool): void
    {
        match ($event['type'] ?? '') {
            'message_start' => $this->absorbStart($accumulator, $event, $state),
            'content_block_start' => $this->absorbBlockStart($accumulator, $event, $state, $structuredTool),
            'content_block_delta' => $this->absorbBlockDelta($accumulator, $event, $state),
            'message_delta' => $this->absorbMessageDelta($accumulator, $event, $state),
            'message_stop' => $accumulator->markDone(),
            'error' => throw $this->streamError($event),
            default => null,
        };
    }

    private function absorbStart(StreamAccumulator $accumulator, array $event, array &$state): void
    {
        $message = $event['message'] ?? [];

        $accumulator->identify((string) ($message['id'] ?? ''), (string) ($message['model'] ?? ''));

        $state['prompt'] = (int) ($message['usage']['input_tokens'] ?? 0);
        $state['cached'] = $message['usage']['cache_read_input_tokens'] ?? null;
        $state['created'] = (int) ($message['usage']['cache_creation_input_tokens'] ?? 0);
        $state['completion'] = (int) ($message['usage']['output_tokens'] ?? 0);
    }

    private function absorbBlockStart(StreamAccumulator $accumulator, array $event, array &$state, ?string $structuredTool): void
    {
        $block = $event['content_block'] ?? [];

        if (($block['type'] ?? '') !== 'tool_use') {
            return;
        }

        $blockIndex = (int) ($event['index'] ?? 0);

        if ($block['name'] === $structuredTool) {
            $state['structured'][$blockIndex] = true;

            return;
        }

        $toolIndex = count($state['toolIndexes']);

        $state['toolIndexes'][$blockIndex] = $toolIndex;

        $accumulator->toolCall($toolIndex, (string) $block['id'], (string) $block['name'], '');
    }

    private function absorbBlockDelta(StreamAccumulator $accumulator, array $event, array &$state): void
    {
        $delta = $event['delta'] ?? [];

        $blockIndex = (int) ($event['index'] ?? 0);

        if (($delta['type'] ?? '') === 'text_delta') {
            $accumulator->text((string) $delta['text']);

            return;
        }

        if (($delta['type'] ?? '') !== 'input_json_delta') {
            return;
        }

        if ($state['structured'][$blockIndex] ?? false) {
            $accumulator->text((string) $delta['partial_json']);

            return;
        }

        $accumulator->toolCall($state['toolIndexes'][$blockIndex] ?? 0, null, null, (string) $delta['partial_json']);
    }

    private function absorbMessageDelta(StreamAccumulator $accumulator, array $event, array &$state): void
    {
        $reason = $event['delta']['stop_reason'] ?? null;

        if ($reason !== null) {
            $accumulator->finish($this->streamFinishReason($reason, $state));
        }

        $state['completion'] = (int) ($event['usage']['output_tokens'] ?? $state['completion']);
    }

    private function streamFinishReason(string $reason, array $state): FinishReason
    {
        if ($reason === 'tool_use' && $state['structured'] !== []) {
            return FinishReason::Stop;
        }

        return FinishReason::normalize($reason, self::FINISH_REASONS, $this->name());
    }

    private function streamError(array $event): ModelRequestFailed
    {
        $type = (string) ($event['error']['type'] ?? '');

        $message = 'The model server sent a stream error: '.json_encode($event['error'] ?? []);

        if ($type === 'overloaded_error') {
            return new ProviderUnavailable($message);
        }

        return new ModelRequestFailed($message);
    }

    private function decodeEvent(string $payload): array
    {
        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw ModelRequestFailed::malformedStreamChunk($payload);
        }

        return $event;
    }

    private function logExchange(ChatRequest $request, array $body, mixed $response): void
    {
        if (! $this->debug->enabled($request->feature, $request->conversationId)) {
            return;
        }

        $this->debug->write($this->name(), $request->feature, $request->conversationId, '/v1/messages', $body, $response);
    }
}
