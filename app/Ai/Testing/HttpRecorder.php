<?php

namespace App\Ai\Testing;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpRecorder
{
    private const REDACTED_HEADERS = ['authorization', 'x-api-key', 'x-goog-api-key', 'api-key'];

    private const REDACTED = '[redacted]';

    private ?array $request = null;

    private ?array $response = null;

    public function start(): void
    {
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            $this->request = $this->describeRequest($request);

            return $request;
        });

        Http::globalResponseMiddleware(function (ResponseInterface $response): ResponseInterface {
            $body = (string) $response->getBody();

            $this->response = [
                'status' => $response->getStatusCode(),
                'headers' => $this->describeHeaders($response->getHeaders()),
                'body' => $body,
            ];

            return $response->withBody(Utils::streamFor($body));
        });
    }

    public function exchange(): array
    {
        return [
            'request' => $this->request,
            'response' => $this->response,
        ];
    }

    public function hasExchange(): bool
    {
        return $this->request !== null && $this->response !== null;
    }

    private function describeRequest(RequestInterface $request): array
    {
        $body = (string) $request->getBody();

        $request->getBody()->rewind();

        $decoded = json_decode($body, true);

        return [
            'method' => $request->getMethod(),
            'url' => $this->redactedUrl((string) $request->getUri()),
            'headers' => $this->describeHeaders($request->getHeaders()),
            'body' => is_array($decoded) ? $decoded : $body,
        ];
    }

    private function describeHeaders(array $headers): array
    {
        $described = [];

        foreach ($headers as $name => $values) {
            $described[$name] = in_array(strtolower($name), self::REDACTED_HEADERS, true)
                ? self::REDACTED
                : implode(', ', $values);
        }

        return $described;
    }

    private function redactedUrl(string $url): string
    {
        return (string) preg_replace('/([?&]key=)[^&]+/', '$1'.self::REDACTED, $url);
    }
}
