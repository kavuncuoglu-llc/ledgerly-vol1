<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class PromptTooLarge extends RuntimeException
{
    public function __construct(
        public readonly int $promptTokens,
        public readonly int $window,
    ) {
        parent::__construct("Prompt of {$promptTokens} tokens does not fit the {$window} token window once the answer is reserved.");
    }
}
