<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Failed;
use App\Ai\Contracts\Usage;
use App\Ai\Drivers\Support\Aws\EventStreamReader;
use App\Ai\Drivers\Support\BedrockClient;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\StreamAccumulator;
use App\Ai\Drivers\Support\WarnOnce;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use Generator;
use RuntimeException;

final class BedrockDriver implements ChatModel
{
    private const FINISH_REASONS = [
        'end_turn' => FinishReason::Stop,
        'stop_sequence' => FinishReason::Stop,
        'max_tokens' => FinishReason::Length,
        'tool_use' => FinishReason::ToolCalls,
        'content_filtered' => FinishReason::ContentFilter,
        'guardrail_intervened' => FinishReason::ContentFilter,
    ];

    private const INFERENCE_OPTIONS = [
        'max_tokens' => 'maxTokens',
        'temperature' => 'temperature',
        'top_p' => 'topP',
        'stop' => 'stopSequences',
    ];

    private const ADDITIONAL_FIELDS = [
        'top_k' => 'top_k',
    ];

    private const DEFAULT_MAX_TOKENS = 1024;

    private const IMAGE_FORMATS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/jpg' => 'jpeg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private readonly BedrockClient $client;

    public function __construct(
        private readonly array $config,
        private readonly DebugLog $debug,
        ?BedrockClient $client = null,
    ) {
        $this->client = $client ?? new BedrockClient($config);
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Tools, Capability::StructuredOutput, Capability::Vision, Capability::CacheBreakpoints => true,
            Capability::Seed, Capability::Reasoning => false,
        };
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $modelId = $this->modelIdOf($request);

        $body = $this->body($request);

        $response = $this->client->converse($modelId, $body);

        $this->logExchange($request, $modelId, $body, $response->json());

        return $this->toResponse(
            body: $response->json() ?? [],
            modelId: $modelId,
            requestId: (string) $response->header('x-amzn-RequestId'),
            structuredTool: $this->structuredToolName($request),
        );
    }

    public function stream(ChatRequest $request): Generator
    {
        $modelId = $this->modelIdOf($request);

        $body = $this->body($request);

        $response = $this->client->converse($modelId, $body, stream: true);

        $accumulator = new StreamAccumulator;

        $accumulator->identify((string) $response->header('x-amzn-RequestId'), $modelId);

        $state = [
            'prompt' => 0,
            'cached' => null,
            'created' => 0,
            'completion' => 0,
            'toolIndexes' => [],
            'structured' => [],
            'stopped' => false,
        ];

        $interruption = null;

        $structuredTool = $this->structuredToolName($request);

        try {
            foreach (EventStreamReader::messages($response->toPsrResponse()->getBody()) as $message) {
                $this->absorb($accumulator, $message, $state, $structuredTool);

                yield from $accumulator->drain();
            }
        } catch (ModelRequestFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $interruption = $exception;
        }

        if ($state['stopped']) {
            $accumulator->markDone();
        }

        $accumulator->reportUsage($this->usageFrom($state));

        $completed = $accumulator->toResponse($modelId);

        $this->logExchange($request, $modelId, $body, [
            'content' => $completed->content,
            'tool_calls' => $completed->toolCalls,
            'finish_reason' => $completed->finishReason->value,
            'usage' => $completed->usage->toArray(),
        ]);

        if (! $accumulator->isDone()) {
            yield new Failed(
                reason: $interruption?->getMessage() ?? 'The stream ended before messageStop.',
                partial: $accumulator->toResponse($modelId, null, FinishReason::Truncated),
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

    private function modelIdOf(ChatRequest $request): string
    {
        return (string) ($request->model ?? $this->config['model'] ?? '');
    }

    private function body(ChatRequest $request): array
    {
        $translated = $this->translateMessages($request);

        $system = $this->systemWithSchemaInstruction($translated['system'], $request);

        $body = [
            'messages' => $translated['messages'],
            'inferenceConfig' => $this->inferenceConfig($request->options),
        ];

        if ($system !== []) {
            $body['system'] = $system;
        }

        $additional = $this->additionalFields($request->options);

        if ($additional !== []) {
            $body['additionalModelRequestFields'] = $additional;
        }

        $toolConfig = $this->toolConfig($request);

        if ($toolConfig !== []) {
            $body['toolConfig'] = $toolConfig;
        }

        return $body;
    }

    private function inferenceConfig(array $options): array
    {
        $config = [];

        foreach ($options as $name => $value) {
            if (isset(self::INFERENCE_OPTIONS[$name])) {
                $config[self::INFERENCE_OPTIONS[$name]] = $value;

                continue;
            }

            if (! isset(self::ADDITIONAL_FIELDS[$name])) {
                WarnOnce::droppedOption($this->name(), (string) $name);
            }
        }

        $config['maxTokens'] ??= (int) ($this->config['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);

        if (isset($config['stopSequences'])) {
            $config['stopSequences'] = (array) $config['stopSequences'];
        }

        return $config;
    }

    private function additionalFields(array $options): array
    {
        $fields = [];

        foreach (self::ADDITIONAL_FIELDS as $option => $field) {
            if (isset($options[$option])) {
                $fields[$field] = $options[$option];
            }
        }

        return $fields;
    }

    private function toolConfig(ChatRequest $request): array
    {
        $tools = array_map(fn (array $tool): array => $this->translateTool($tool), $request->tools);

        $choice = $this->translateToolChoice($request->toolChoice);

        $structured = $this->structuredToolName($request);

        if ($structured !== null) {
            $tools[] = [
                'toolSpec' => [
                    'name' => $structured,
                    'description' => 'Return the final answer by calling this tool with arguments that match the schema.',
                    'inputSchema' => [
                        'json' => $request->responseFormat['json_schema']['schema'] ?? ['type' => 'object'],
                    ],
                ],
            ];

            $choice = [
                'tool' => [
                    'name' => $structured,
                ],
            ];
        }

        if ($tools === []) {
            return [];
        }

        $config = [
            'tools' => $tools,
        ];

        if ($choice !== null) {
            $config['toolChoice'] = $choice;
        }

        return $config;
    }

    private function structuredToolName(ChatRequest $request): ?string
    {
        if ($request->responseFormat === null) {
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
            'text' => "Reply with a single JSON object and nothing else. It must satisfy this JSON Schema: {$schema}",
        ];

        return $system;
    }

    private function translateTool(array $tool): array
    {
        $function = $tool['function'] ?? $tool;

        return [
            'toolSpec' => [
                'name' => $function['name'],
                'description' => $function['description'] ?? '',
                'inputSchema' => [
                    'json' => $function['parameters'] ?? ['type' => 'object'],
                ],
            ],
        ];
    }

    private function translateToolChoice(string|array|null $choice): ?array
    {
        if ($choice === null) {
            return null;
        }

        if ($choice === 'auto') {
            return ['auto' => (object) []];
        }

        if ($choice === 'required') {
            return ['any' => (object) []];
        }

        if (is_array($choice)) {
            return [
                'tool' => [
                    'name' => $choice['function']['name'] ?? $choice['name'] ?? '',
                ],
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
                array_push($system, ...$this->withCachePoint([['text' => $this->textOf($message['content'] ?? '')]], $breakpoint));

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
                'toolUse' => [
                    'toolUseId' => (string) ($call['id'] ?? ''),
                    'name' => $call['function']['name'],
                    'input' => $this->decodedArguments((string) ($call['function']['arguments'] ?? '')),
                ],
            ];
        }

        return [
            'role' => $message['role'],
            'content' => $this->withCachePoint($blocks, $breakpoint),
        ];
    }

    private function withCachePoint(array $blocks, bool $breakpoint): array
    {
        if (! $breakpoint) {
            return $blocks;
        }

        $blocks[] = [
            'cachePoint' => [
                'type' => 'default',
            ],
        ];

        return $blocks;
    }

    private function appendToolResult(array $messages, array $message): array
    {
        $block = [
            'toolResult' => [
                'toolUseId' => (string) ($message['tool_call_id'] ?? ''),
                'content' => [
                    ['text' => $this->textOf($message['content'] ?? '')],
                ],
            ],
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

        return isset($content[array_key_last($content)]['toolResult']);
    }

    private function blocksOf(mixed $content): array
    {
        if (is_string($content)) {
            if ($content === '') {
                return [];
            }

            return [['text' => $content]];
        }

        if (! is_array($content)) {
            return [];
        }

        return array_map(fn (array $part): array => $this->translatePart($part), $content);
    }

    private function translatePart(array $part): array
    {
        if (($part['type'] ?? '') !== 'image_url') {
            return ['text' => (string) ($part['text'] ?? '')];
        }

        $url = (string) ($part['image_url']['url'] ?? '');

        if (preg_match('#^data:(?<media>[^;]+);base64,(?<data>.+)$#s', $url, $matches) !== 1) {
            return ['text' => ''];
        }

        return [
            'image' => [
                'format' => self::IMAGE_FORMATS[$matches['media']] ?? 'png',
                'source' => [
                    'bytes' => $matches['data'],
                ],
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

    private function toResponse(array $body, string $modelId, string $requestId, ?string $structuredTool): ChatResponse
    {
        $content = $body['output']['message']['content'] ?? null;

        if (! is_array($content)) {
            throw ModelRequestFailed::malformed('output.message.content');
        }

        $text = '';

        $toolCalls = [];

        foreach ($content as $block) {
            if (isset($block['text'])) {
                $text .= (string) $block['text'];
            }

            if (isset($block['toolUse'])) {
                $toolCalls[] = $this->normalizedToolCall($block['toolUse']);
            }
        }

        $reason = FinishReason::normalize($body['stopReason'] ?? null, self::FINISH_REASONS, $this->name());

        if ($structuredTool !== null && count($toolCalls) === 1 && $toolCalls[0]['function']['name'] === $structuredTool) {
            return $this->structuredResponse($body, $modelId, $requestId, $toolCalls[0]);
        }

        return new ChatResponse(
            id: $requestId,
            model: $modelId,
            content: $text === '' && $toolCalls !== []
                ? null
                : $text,
            toolCalls: $toolCalls,
            finishReason: $reason,
            usage: $this->usageOfBody($body),
            raw: $body,
        );
    }

    private function structuredResponse(array $body, string $modelId, string $requestId, array $call): ChatResponse
    {
        return new ChatResponse(
            id: $requestId,
            model: $modelId,
            content: $call['function']['arguments'],
            toolCalls: [],
            finishReason: ($body['stopReason'] ?? null) === 'max_tokens'
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
            'id' => (string) $block['toolUseId'],
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
            'prompt' => (int) ($body['usage']['inputTokens'] ?? 0),
            'cached' => $body['usage']['cacheReadInputTokens'] ?? null,
            'created' => (int) ($body['usage']['cacheWriteInputTokens'] ?? 0),
            'completion' => (int) ($body['usage']['outputTokens'] ?? 0),
        ]);
    }

    private function usageFrom(array $state): Usage
    {
        $cached = $state['cached'] ?? null;

        $cachedTokens = $cached === null
            ? null
            : (int) $cached;

        return new Usage(
            promptTokens: (int) $state['prompt'] + (int) ($cachedTokens ?? 0) + (int) ($state['created'] ?? 0),
            completionTokens: (int) $state['completion'],
            cachedTokens: $cachedTokens,
        );
    }

    private function absorb(StreamAccumulator $accumulator, array $message, array &$state, ?string $structuredTool): void
    {
        $headers = $message['headers'];

        if (($headers[':message-type'] ?? 'event') === 'exception') {
            throw $this->client->errors()->fromStreamException((string) ($headers[':exception-type'] ?? 'unknown'), $message['payload']);
        }

        $event = json_decode($message['payload'], true);

        if (! is_array($event)) {
            throw ModelRequestFailed::malformedStreamChunk($message['payload']);
        }

        match ($headers[':event-type'] ?? '') {
            'contentBlockStart' => $this->absorbBlockStart($accumulator, $event, $state, $structuredTool),
            'contentBlockDelta' => $this->absorbBlockDelta($accumulator, $event, $state),
            'messageStop' => $this->absorbStop($accumulator, $event, $state),
            'metadata' => $this->absorbMetadata($event, $state),
            default => null,
        };
    }

    private function absorbBlockStart(StreamAccumulator $accumulator, array $event, array &$state, ?string $structuredTool): void
    {
        $toolUse = $event['start']['toolUse'] ?? null;

        if ($toolUse === null) {
            return;
        }

        $blockIndex = (int) ($event['contentBlockIndex'] ?? 0);

        if ($toolUse['name'] === $structuredTool) {
            $state['structured'][$blockIndex] = true;

            return;
        }

        $toolIndex = count($state['toolIndexes']);

        $state['toolIndexes'][$blockIndex] = $toolIndex;

        $accumulator->toolCall($toolIndex, (string) $toolUse['toolUseId'], (string) $toolUse['name'], '');
    }

    private function absorbBlockDelta(StreamAccumulator $accumulator, array $event, array &$state): void
    {
        $delta = $event['delta'] ?? [];

        $blockIndex = (int) ($event['contentBlockIndex'] ?? 0);

        if (isset($delta['text'])) {
            $accumulator->text((string) $delta['text']);

            return;
        }

        if (! isset($delta['toolUse']['input'])) {
            return;
        }

        if ($state['structured'][$blockIndex] ?? false) {
            $accumulator->text((string) $delta['toolUse']['input']);

            return;
        }

        $accumulator->toolCall($state['toolIndexes'][$blockIndex] ?? 0, null, null, (string) $delta['toolUse']['input']);
    }

    private function absorbStop(StreamAccumulator $accumulator, array $event, array &$state): void
    {
        $state['stopped'] = true;

        $reason = (string) ($event['stopReason'] ?? '');

        if ($reason === 'tool_use' && $state['structured'] !== []) {
            $accumulator->finish(FinishReason::Stop);

            return;
        }

        $accumulator->finish(FinishReason::normalize($reason, self::FINISH_REASONS, $this->name()));
    }

    private function absorbMetadata(array $event, array &$state): void
    {
        $state['prompt'] = (int) ($event['usage']['inputTokens'] ?? 0);
        $state['cached'] = $event['usage']['cacheReadInputTokens'] ?? null;
        $state['created'] = (int) ($event['usage']['cacheWriteInputTokens'] ?? 0);
        $state['completion'] = (int) ($event['usage']['outputTokens'] ?? 0);
    }

    private function logExchange(ChatRequest $request, string $modelId, array $body, mixed $response): void
    {
        if (! $this->debug->enabled($request->feature, $request->conversationId)) {
            return;
        }

        $this->debug->write($this->name(), $request->feature, $request->conversationId, '/model/'.rawurlencode($modelId).'/converse', $body, $response);
    }
}
