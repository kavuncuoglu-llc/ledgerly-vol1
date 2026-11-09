<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class NoCustomerAccount extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct("User [{$userId}] has no customer account for tools to act on.");
    }
}
