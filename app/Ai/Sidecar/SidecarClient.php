<?php

namespace App\Ai\Sidecar;

use App\Ai\Exceptions\SidecarFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class SidecarClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $parseTimeout,
        private readonly int $maxUploadMegabytes,
    ) {}

    public function rerank(string $question, array $passages): array
    {
        $scores = $this->send(
            fn (): array => $this->http($this->timeout)
                ->post('/rerank', [
                    'question' => $question,
                    'passages' => array_values($passages),
                ])
                ->throw()
                ->json() ?? [],
            'scores',
        );

        if (! is_array($scores)) {
            throw SidecarFailed::malformed('/rerank');
        }

        return array_map('floatval', array_values($scores));
    }

    public function parse(string $path): string
    {
        $contents = $this->contentsOf($path);

        $markdown = $this->send(
            fn (): array => $this->http($this->parseTimeout)
                ->attach('file', $contents, basename($path))
                ->post('/parse')
                ->throw()
                ->json() ?? [],
            'markdown',
        );

        if (! is_string($markdown)) {
            throw SidecarFailed::malformed('/parse');
        }

        return $markdown;
    }

    private function send(callable $request, string $key): mixed
    {
        try {
            return $request()[$key] ?? null;
        } catch (ConnectionException $exception) {
            throw SidecarFailed::unreachable($this->baseUrl, $exception);
        } catch (RequestException $exception) {
            throw SidecarFailed::rejected($exception->response->status(), $exception->response->body());
        }
    }

    private function contentsOf(string $path): string
    {
        $size = @filesize($path);

        if ($size === false) {
            throw SidecarFailed::unreadableFile($path);
        }

        if ($size > $this->maxUploadMegabytes * 1024 * 1024) {
            throw SidecarFailed::fileTooLarge($path, $this->maxUploadMegabytes);
        }

        return (string) file_get_contents($path);
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->validatedBaseUrl())
            ->acceptJson()
            ->timeout($timeout);
    }

    private function validatedBaseUrl(): string
    {
        $parts = parse_url($this->baseUrl);

        $scheme = $parts['scheme'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw SidecarFailed::invalidUrl($this->baseUrl);
        }

        if (($parts['host'] ?? '') === '') {
            throw SidecarFailed::invalidUrl($this->baseUrl);
        }

        return rtrim($this->baseUrl, '/');
    }
}
