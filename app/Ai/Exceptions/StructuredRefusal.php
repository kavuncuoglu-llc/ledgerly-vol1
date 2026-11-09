<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class StructuredRefusal extends RuntimeException
{
    public function __construct(
        public readonly string $class,
        public readonly string $reason,
        public readonly array $attempts,
    ) {
        parent::__construct(sprintf(
            'The model declined to fill %s: %s',
            class_basename($class),
            $reason,
        ));
    }
}
