<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

final class StreamTruncated extends RuntimeException
{
    public function __construct(
        public readonly string $partialContent,
        public readonly ?string $finishReason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The model stream ended before [DONE]; the reply is incomplete.', previous: $previous);
    }
}
