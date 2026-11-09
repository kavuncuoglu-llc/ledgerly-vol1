<?php

namespace App\Ai\Vision;

final class ExtractedInvoice
{
    private const MISSING = '---';

    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly array $data,
    ) {}

    public function display(string $field): string
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return self::MISSING;
        }

        return (string) $value;
    }

    public function displayTotal(): string
    {
        if (! $this->hasTotal()) {
            return self::MISSING;
        }

        return number_format($this->total(), 2);
    }

    public function hasTotal(): bool
    {
        return $this->toAmount($this->data['total'] ?? null) !== null;
    }

    public function total(): float
    {
        return $this->toAmount($this->data['total'] ?? null) ?? 0.0;
    }

    public function lineItemsTotal(): float
    {
        $lineItems = $this->data['line_items'] ?? [];

        if (! is_array($lineItems)) {
            return 0.0;
        }

        return array_sum(array_map(
            fn (mixed $line): float => $this->toAmount(is_array($line) ? ($line['amount'] ?? null) : null) ?? 0.0,
            $lineItems,
        ));
    }

    public function totalsAgree(): bool
    {
        if (! $this->hasTotal()) {
            return false;
        }

        return abs($this->total() - $this->lineItemsTotal()) < self::TOLERANCE;
    }

    private function toAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $cleaned = str_replace([',', ' '], '', preg_replace('/[^\d.,\-\s]/', '', $value));

        if (! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }
}
