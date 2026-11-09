<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class InvalidStatsWindow extends InvalidArgumentException
{
    public function __construct(string $given)
    {
        parent::__construct("[{$given}] is not a window. Use a number and a unit: 90m, 24h or 7d.");
    }
}
