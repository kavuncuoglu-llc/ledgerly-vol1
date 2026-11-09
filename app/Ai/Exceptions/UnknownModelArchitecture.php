<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnknownModelArchitecture extends RuntimeException
{
    public static function for(string $model, array $missing): self
    {
        return new self(sprintf(
            'Cannot size [%s]: unknown %s. Pass %s from the model card, or pull the model so the server can tell us.',
            $model,
            implode(', ', $missing),
            implode(', ', array_map(fn (string $field): string => '--'.str_replace('_', '-', $field).'=', $missing)),
        ));
    }
}
