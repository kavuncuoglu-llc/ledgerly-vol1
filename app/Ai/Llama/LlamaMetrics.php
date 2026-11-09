<?php

namespace App\Ai\Llama;

final readonly class LlamaMetrics
{
    private const PREFIX = 'llamacpp:';

    public function __construct(private array $values) {}

    public static function parse(string $text): self
    {
        $values = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^'.preg_quote(self::PREFIX, '/').'([a-z_]+)(?:\{[^}]*\})?\s+(\S+)$/', $line, $matches) !== 1) {
                continue;
            }

            if (! is_numeric($matches[2])) {
                continue;
            }

            $values[$matches[1]] = ($values[$matches[1]] ?? 0.0) + (float) $matches[2];
        }

        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function value(string $name): float
    {
        return $this->values[$name] ?? 0.0;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function promptCacheHitRatio(): float
    {
        $cached = $this->value('prompt_tokens_cached_total');

        $total = $cached + $this->value('prompt_tokens_total');

        if ($total === 0.0) {
            return 0.0;
        }

        return $cached / $total;
    }

    public function draftAcceptanceRatio(): float
    {
        $drafted = $this->value('spec_decode_num_draft_tokens_total');

        if ($drafted === 0.0) {
            return 0.0;
        }

        return $this->value('spec_decode_num_accepted_tokens_total') / $drafted;
    }
}
