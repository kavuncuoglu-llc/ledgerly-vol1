<?php

namespace App\Ai\Tokens;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Llama\LlamaServerClient;

final class LlamaServerTokenCounter implements TokenCounter
{
    private const MEMO_LIMIT = 5000;

    private array $counted = [];

    public function __construct(private readonly LlamaServerClient $client) {}

    public static function forProvider(array $providerConfig): self
    {
        return new self(LlamaServerClient::forProvider($providerConfig));
    }

    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $key = md5($text);

        if (isset($this->counted[$key])) {
            return $this->counted[$key];
        }

        if (count($this->counted) >= self::MEMO_LIMIT) {
            $this->counted = [];
        }

        return $this->counted[$key] = $this->tokenize($text);
    }

    private function tokenize(string $text): int
    {
        $reply = $this->client->tokenize($text);

        if (! $reply->reachable()) {
            throw new ProviderUnavailable("Could not reach llama-server at {$this->client->baseUrl()} to count tokens: {$reply->error}");
        }

        if (! $reply->ok()) {
            throw ModelRequestFailed::rejected($reply->status, $reply->text);
        }

        $tokens = $reply->body['tokens'] ?? null;

        if (! is_array($tokens)) {
            throw ModelRequestFailed::malformed('tokens');
        }

        return count($tokens);
    }
}
