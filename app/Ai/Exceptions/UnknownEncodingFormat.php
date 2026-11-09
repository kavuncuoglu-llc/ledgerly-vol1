<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class UnknownEncodingFormat extends InvalidArgumentException
{
    public function __construct(
        public readonly string $format,
        public readonly array $available,
    ) {
        parent::__construct("Unknown encoding format [{$format}]. Available: ".implode(', ', $available).'.');
    }
}
