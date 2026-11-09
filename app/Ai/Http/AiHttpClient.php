<?php

namespace App\Ai\Http;

use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Exceptions\UnknownSamplingProfile;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class AiHttpClient
{
    public function __construct(private readonly array $config) {}

    public function chat(array $messages, array $options = [], string $feature = 'unknown', ?string $promptVersion = null, bool $repaired = false, ?int $conversationId = null, ?CallDetails $details = null): ChatResponse
    {
        $body = [
            ...$this->buildBody($messages, $options),
            'stream' => false,
        ];

        $started = hrtime(true);

        $response = $this->post('/chat/completions', $body);

        $latencyMs = $this->elapsedMilliseconds($started);

        $chat = ChatResponse::fromArray($response->json());

        $cached = $this->cachedTokens($chat, $feature, $conversationId, $messages);

        $event = new ModelCalled(
            $feature,
            $this->config['provider'],
            $chat->model,
            $chat->usage->toArray(),
            $chat->finishReason,
            $latencyMs,
            conversationId: $conversationId,
            promptVersion: $promptVersion,
            repaired: $repaired,
            cachedTokens: $cached['tokens'],
            contextBreakdown: $details?->contextBreakdown,
            semanticCacheHit: $details?->semanticCacheHit,
            cachedTokensEstimated: $cached['estimated'],
        );

        event($event);

        return $chat->withModelCallId($event->modelCallId);
    }

    public function stream(array $messages, array $options = [], string $feature = 'unknown', ?string $promptVersion = null): Generator
    {
        $body = [
            ...$this->buildBody($messages, $options),
            'stream' => true,
            'stream_options' => [
                'include_usage' => true,
            ],
        ];

        $started = hrtime(true);

        $response = $this->post('/chat/completions', $body, [
            'stream' => true,
        ]);

        $accumulator = new StreamAccumulator;

        $interruption = null;

        $firstDeltaMs = null;

        try {
            foreach ($this->payloads($response->toPsrResponse()->getBody()) as $payload) {
                if ($payload === '[DONE]') {
                    $accumulator->markDone();

                    break;
                }

                $delta = $accumulator->absorb($this->decodeChunk($payload));

                foreach ($accumulator->drainStartedToolCalls() as $toolCallStarted) {
                    yield $toolCallStarted;
                }

                if ($delta === '') {
                    continue;
                }

                $firstDeltaMs ??= $this->elapsedMilliseconds($started);

                yield $delta;
            }
        } catch (ModelRequestFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $interruption = $exception;
        }

        ModelCalled::dispatch(
            $feature,
            $this->config['provider'],
            $accumulator->model($body['model']),
            $accumulator->usage()->toArray(),
            $this->streamFinishReason($accumulator),
            $this->elapsedMilliseconds($started),
            promptVersion: $promptVersion,
            ttftMs: $firstDeltaMs,
        );

        if (! $accumulator->isDone()) {
            throw new StreamTruncated($accumulator->content(), $accumulator->finishReason(), $interruption);
        }

        return $accumulator->toResponse($body['model']);
    }

    public function post(string $path, array $body, array $requestOptions = []): Response
    {
        try {
            return $this->http()
                ->withOptions($requestOptions)
                ->post($path, $body);
        } catch (ConnectionException $exception) {
            throw ModelRequestFailed::unreachable($this->config['base_url'], $exception);
        } catch (RequestException $exception) {
            throw ModelRequestFailed::rejected($exception->response->status(), $exception->response->body());
        }
    }

    private function cachedTokens(ChatResponse $chat, string $feature, ?int $conversationId, array $messages): array
    {
        $reported = $chat->usage->extras['prompt_tokens_details']['cached_tokens'] ?? null;

        if ($conversationId === null) {
            return [
                'tokens' => $reported === null ? null : (int) $reported,
                'estimated' => false,
            ];
        }

        $estimate = app(PrefixCacheEstimator::class)->estimate($feature, $conversationId, $messages, $chat->usage->promptTokens);

        if ($reported !== null) {
            return [
                'tokens' => (int) $reported,
                'estimated' => false,
            ];
        }

        return [
            'tokens' => $estimate,
            'estimated' => true,
        ];
    }

    private function http(): PendingRequest
    {
        return app(RetryPolicy::class)->apply(
            Http::baseUrl($this->config['base_url'])
                ->withToken($this->config['api_key'])
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'])
                ->timeout($this->config['timeout'])
        );
    }

    private function buildBody(array $messages, array $options): array
    {
        $profile = $this->profileFor($options);

        unset($options['profile']);

        return [
            ...$profile,
            ...$options,
            'model' => $options['model'] ?? $this->config['model'],
            'messages' => $messages,
        ];
    }

    private function profileFor(array $options): array
    {
        $name = array_key_exists('profile', $options)
            ? $options['profile']
            : 'assistant';

        if ($name === null) {
            return [];
        }

        return $this->config['profiles'][$name] ?? throw new UnknownSamplingProfile($name);
    }

    private function payloads(StreamInterface $stream): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $payload = $this->payloadOf(substr($buffer, 0, $newline));

                $buffer = substr($buffer, $newline + 1);

                if ($payload !== '') {
                    yield $payload;
                }
            }
        }

        $remainder = $this->payloadOf($buffer);

        if ($remainder !== '') {
            yield $remainder;
        }
    }

    private function payloadOf(string $line): string
    {
        $line = trim($line);

        if (! str_starts_with($line, 'data:')) {
            return '';
        }

        return trim(substr($line, 5));
    }

    private function decodeChunk(string $payload): array
    {
        $chunk = json_decode($payload, true);

        if (! is_array($chunk)) {
            throw ModelRequestFailed::malformedStreamChunk($payload);
        }

        return $chunk;
    }

    private function streamFinishReason(StreamAccumulator $accumulator): string
    {
        if (! $accumulator->isDone()) {
            return 'truncated';
        }

        return $accumulator->finishReason() ?? 'stop';
    }

    private function elapsedMilliseconds(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
