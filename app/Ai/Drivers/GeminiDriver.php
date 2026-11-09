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
use App\Ai\Drivers\Errors\GeminiErrors;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Drivers\Support\GeminiSchema;
use App\Ai\Drivers\Support\OptionMapper;
use App\Ai\Drivers\Support\ProviderClient;
use App\Ai\Drivers\Support\SseReader;
use App\Ai\Drivers\Support\StreamAccumulator;
use App\Ai\Exceptions\ContentFiltered;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use Generator;
use RuntimeException;

final class GeminiDriver implements ChatModel
{
    private const FINISH_REASONS = [
        'STOP' => FinishReason::Stop,
        'FINISH_REASON_UNSPECIFIED' => FinishReason::Stop,
        'MAX_TOKENS' => FinishReason::Length,
        'FUNCTION_CALL' => FinishReason::ToolCalls,
        'SAFETY' => FinishReason::ContentFilter,
        'RECITATION' => FinishReason::ContentFilter,
        'BLOCKLIST' => FinishReason::ContentFilter,
        'PROHIBITED_CONTENT' => FinishReason::ContentFilter,
        'SPII' => FinishReason::ContentFilter,
    ];

    private const GENERATION_RENAMES = [
        'max_tokens' => 'maxOutputTokens',
        'temperature' => 'temperature',
        'top_p' => 'topP',
        'top_k' => 'topK',
        'stop' => 'stopSequences',
    ];

    private const UNSUPPORTED_OPTIONS = ['seed', 'min_p', 'frequency_penalty', 'presence_penalty', 'repeat_penalty'];

    private readonly ProviderClient $client;

    public function __construct(
        private readonly array $config,
        private readonly DebugLog $debug,
        ?ProviderClient $client = null,
    ) {
        $this->client = $client ?? new ProviderClient(
            config: $config,
            errors: new GeminiErrors,
            headers: ['x-goog-api-key' => (string) ($config['api_key'] ?? '')],
        );
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Tools, Capability::StructuredOutput, Capability::Vision => true,
            Capability::CacheBreakpoints, Capability::Seed => false,
            Capability::Reasoning => (bool) ($this->config['reasoning'] ?? false),
        };
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $body = $this->body($request);

        $path = $this->pathFor($request, 'generateContent');

        $response = $this->client->post($path, $body);

        $this->logExchange($request, $path, $body, $response->json());

        return $this->toResponse($response->json() ?? [], $request);
    }

    public function stream(ChatRequest $request): Generator
    {
        $body = $this->body($request);

        $path = $this->pathFor($request, 'streamGenerateContent').'?alt=sse';

        $response = $this->client->post($path, $body, stream: true);

        $accumulator = new StreamAccumulator;

        $toolCount = 0;

        $interruption = null;

        try {
            foreach (SseReader::payloads($response->toPsrResponse()->getBody()) as $payload) {
                $this->absorb($accumulator, $this->decodeChunk($payload), $toolCount);

                yield from $accumulator->drain();
            }

            $accumulator->markDone();
        } catch (ModelRequestFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $interruption = $exception;
        }

        $fallbackModel = (string) ($request->model ?? $this->config['model'] ?? '');

        $completed = $accumulator->toResponse($fallbackModel);

        $this->logExchange($request, $path, $body, [
            'content' => $completed->content,
            'tool_calls' => $completed->toolCalls,
            'finish_reason' => $completed->finishReason->value,
            'usage' => $completed->usage->toArray(),
        ]);

        if (! $accumulator->isDone()) {
            yield new Failed(
                reason: $interruption?->getMessage() ?? 'The stream ended unexpectedly.',
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

    private function pathFor(ChatRequest $request, string $action): string
    {
        $model = $request->model ?? $this->config['model'] ?? '';

        return "/v1beta/models/{$model}:{$action}";
    }

    private function body(ChatRequest $request): array
    {
        $translated = $this->translateMessages($request->messages);

        $body = [
            'contents' => $translated['contents'],
        ];

        if ($translated['system'] !== []) {
            $body['systemInstruction'] = [
                'parts' => $translated['system'],
            ];
        }

        $generation = $this->generationConfig($request);

        if ($generation !== []) {
            $body['generationConfig'] = $generation;
        }

        if ($request->tools !== []) {
            $body['tools'] = [
                [
                    'functionDeclarations' => array_map(fn (array $tool): array => $this->translateTool($tool), $request->tools),
                ],
            ];
        }

        $toolConfig = $this->toolConfig($request->toolChoice);

        if ($toolConfig !== null) {
            $body['toolConfig'] = $toolConfig;
        }

        return $body;
    }

    private function generationConfig(ChatRequest $request): array
    {
        $options = (new OptionMapper(
            provider: $this->name(),
            renames: self::GENERATION_RENAMES,
            dropped: self::UNSUPPORTED_OPTIONS,
        ))->map($request->options);

        if (isset($options['stopSequences'])) {
            $options['stopSequences'] = (array) $options['stopSequences'];
        }

        $format = $request->responseFormat['json_schema']['schema'] ?? null;

        if ($format === null) {
            return $options;
        }

        return [
            ...$options,
            'responseMimeType' => 'application/json',
            'responseSchema' => GeminiSchema::clean($format),
        ];
    }

    private function translateTool(array $tool): array
    {
        $function = $tool['function'] ?? $tool;

        $declaration = [
            'name' => $function['name'],
            'description' => $function['description'] ?? '',
        ];

        $parameters = $function['parameters'] ?? [];

        if (($parameters['properties'] ?? []) === []) {
            return $declaration;
        }

        return [...$declaration, 'parameters' => GeminiSchema::clean($parameters)];
    }

    private function toolConfig(string|array|null $choice): ?array
    {
        if ($choice === null) {
            return null;
        }

        if (is_array($choice)) {
            return [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => [$choice['function']['name'] ?? $choice['name'] ?? ''],
                ],
            ];
        }

        $mode = match ($choice) {
            'required' => 'ANY',
            'none' => 'NONE',
            default => 'AUTO',
        };

        return [
            'functionCallingConfig' => [
                'mode' => $mode,
            ],
        ];
    }

    private function translateMessages(array $messages): array
    {
        $system = [];

        $contents = [];

        $names = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ($role === 'system') {
                $system[] = ['text' => $this->textOf($message['content'] ?? '')];

                continue;
            }

            if ($role === 'tool') {
                $contents[] = $this->functionResponse($message, $names);

                continue;
            }

            $contents[] = $this->translateMessage($message, $names);
        }

        return [
            'system' => $system,
            'contents' => $contents,
        ];
    }

    private function translateMessage(array $message, array &$names): array
    {
        $parts = $this->partsOf($message['content'] ?? '');

        foreach ($message['tool_calls'] ?? [] as $call) {
            $names[(string) ($call['id'] ?? '')] = $call['function']['name'];

            $parts[] = [
                'functionCall' => [
                    'name' => $call['function']['name'],
                    'args' => $this->decodedArguments((string) ($call['function']['arguments'] ?? '')),
                ],
            ];
        }

        return [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => $parts,
        ];
    }

    private function functionResponse(array $message, array $names): array
    {
        $content = $this->textOf($message['content'] ?? '');

        $decoded = json_decode($content, true);

        return [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => [
                        'name' => $names[(string) ($message['tool_call_id'] ?? '')] ?? (string) ($message['name'] ?? 'tool'),
                        'response' => is_array($decoded) && $decoded !== []
                            ? $decoded
                            : ['result' => $content],
                    ],
                ],
            ],
        ];
    }

    private function partsOf(mixed $content): array
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

        if (preg_match('#^data:(?<media>[^;]+);base64,(?<data>.+)$#s', $url, $matches) === 1) {
            return [
                'inlineData' => [
                    'mimeType' => $matches['media'],
                    'data' => $matches['data'],
                ],
            ];
        }

        return [
            'fileData' => [
                'fileUri' => $url,
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

    private function toResponse(array $body, ChatRequest $request): ChatResponse
    {
        $candidate = $body['candidates'][0] ?? null;

        if ($candidate === null) {
            throw $this->withoutCandidate($body);
        }

        $text = '';

        $toolCalls = [];

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = $this->normalizedToolCall(count($toolCalls), $part['functionCall']);
            }
        }

        return new ChatResponse(
            id: (string) ($body['responseId'] ?? ''),
            model: (string) ($body['modelVersion'] ?? $request->model ?? $this->config['model'] ?? ''),
            content: $text === '' && $toolCalls !== [] ? null : $text,
            toolCalls: $toolCalls,
            finishReason: $this->finishReasonOf($candidate['finishReason'] ?? null, $toolCalls !== []),
            usage: $this->usageOf($body['usageMetadata'] ?? []),
            raw: $body,
        );
    }

    private function withoutCandidate(array $body): ModelRequestFailed
    {
        $blocked = $body['promptFeedback']['blockReason'] ?? null;

        if ($blocked !== null) {
            return new ContentFiltered("The prompt was blocked: {$blocked}");
        }

        return ModelRequestFailed::malformed('candidates[0]');
    }

    private function assertNotBlocked(array $chunk): void
    {
        if (isset($chunk['promptFeedback']['blockReason'])) {
            throw $this->withoutCandidate($chunk);
        }
    }

    private function finishReasonOf(?string $provided, bool $hasToolCalls): FinishReason
    {
        $reason = FinishReason::normalize($provided, self::FINISH_REASONS, $this->name());

        if ($reason === FinishReason::Stop && $hasToolCalls) {
            return FinishReason::ToolCalls;
        }

        return $reason;
    }

    private function normalizedToolCall(int $index, array $call): array
    {
        return [
            'id' => "call_{$index}",
            'type' => 'function',
            'function' => [
                'name' => (string) $call['name'],
                'arguments' => $this->encodedArguments($call['args'] ?? []),
            ],
        ];
    }

    private function encodedArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '{}';
        }

        return (string) json_encode($arguments, JSON_UNESCAPED_SLASHES);
    }

    private function usageOf(array $metadata): Usage
    {
        $cached = $metadata['cachedContentTokenCount'] ?? null;

        $thoughts = $metadata['thoughtsTokenCount'] ?? null;

        return new Usage(
            promptTokens: (int) ($metadata['promptTokenCount'] ?? 0),
            completionTokens: (int) ($metadata['candidatesTokenCount'] ?? 0) + (int) ($thoughts ?? 0),
            cachedTokens: $cached === null ? null : (int) $cached,
            reasoningTokens: $thoughts === null ? null : (int) $thoughts,
        );
    }

    private function absorb(StreamAccumulator $accumulator, array $chunk, int &$toolCount): void
    {
        $accumulator->identify((string) ($chunk['responseId'] ?? ''), (string) ($chunk['modelVersion'] ?? ''));

        if (isset($chunk['usageMetadata'])) {
            $accumulator->reportUsage($this->usageOf($chunk['usageMetadata']));
        }

        if (! isset($chunk['candidates'][0])) {
            $this->assertNotBlocked($chunk);

            return;
        }

        $candidate = $chunk['candidates'][0];

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && ($part['thought'] ?? false) !== true) {
                $accumulator->text((string) $part['text']);
            }

            if (isset($part['functionCall'])) {
                $accumulator->toolCall(
                    index: $toolCount,
                    id: "call_{$toolCount}",
                    name: (string) $part['functionCall']['name'],
                    arguments: $this->encodedArguments($part['functionCall']['args'] ?? []),
                );

                $toolCount++;
            }
        }

        if (isset($candidate['finishReason'])) {
            $accumulator->finish($this->finishReasonOf($candidate['finishReason'], $toolCount > 0));
        }
    }

    private function decodeChunk(string $payload): array
    {
        $chunk = json_decode($payload, true);

        if (! is_array($chunk)) {
            throw ModelRequestFailed::malformedStreamChunk($payload);
        }

        return $chunk;
    }

    private function logExchange(ChatRequest $request, string $path, array $body, mixed $response): void
    {
        if (! $this->debug->enabled($request->feature, $request->conversationId)) {
            return;
        }

        $this->debug->write($this->name(), $request->feature, $request->conversationId, $path, $body, $response);
    }
}
