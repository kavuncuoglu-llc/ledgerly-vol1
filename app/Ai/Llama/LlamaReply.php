<?php

namespace App\Ai\Llama;

final readonly class LlamaReply
{
    public function __construct(
        public int $status,
        public array $body,
        public string $text,
        public int $milliseconds,
        public string $error = '',
    ) {}

    public static function unreachable(string $error, int $milliseconds): self
    {
        return new self(
            status: 0,
            body: [],
            text: '',
            milliseconds: $milliseconds,
            error: $error,
        );
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function reachable(): bool
    {
        return $this->status > 0;
    }

    public function errorMessage(): string
    {
        if ($this->error !== '') {
            return $this->error;
        }

        $message = $this->body['error']['message'] ?? null;

        if (is_string($message)) {
            return "HTTP {$this->status}: {$message}";
        }

        return "HTTP {$this->status}";
    }
}
