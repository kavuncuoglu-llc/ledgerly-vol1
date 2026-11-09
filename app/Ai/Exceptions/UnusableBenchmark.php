<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnusableBenchmark extends RuntimeException
{
    public static function fixtureMissing(string $path): self
    {
        return new self("No benchmark fixture at {$path}.");
    }

    public static function fixtureMalformed(string $path): self
    {
        return new self("{$path} must be a JSON object with a today date and a list of invoices.");
    }

    public static function questionsMalformed(string $path): self
    {
        return new self("{$path} must be a JSON list of objects with a question and an expected answer.");
    }

    public static function tooFewRows(int $rows, int $minimum): self
    {
        return new self("The benchmark needs at least {$minimum} rows, got {$rows}.");
    }

    public static function noOverdueInvoices(): self
    {
        return new self('The benchmark rows contain no overdue invoice, so the overdue questions cannot be asked.');
    }

    public static function unknownEncoding(string $name, array $available): self
    {
        return new self("Unknown encoding [{$name}]. Available: ".implode(', ', $available).'.');
    }
}
