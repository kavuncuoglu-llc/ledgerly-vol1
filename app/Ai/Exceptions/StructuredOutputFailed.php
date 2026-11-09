<?php

namespace App\Ai\Exceptions;

use Illuminate\Support\MessageBag;
use RuntimeException;

final class StructuredOutputFailed extends RuntimeException
{
    public function __construct(
        public readonly string $class,
        public readonly array $attempts,
        public readonly MessageBag $errors,
    ) {
        parent::__construct(sprintf(
            'The model output for %s was still invalid after one repair: %s',
            class_basename($class),
            implode(' ', $errors->all()),
        ));
    }
}
