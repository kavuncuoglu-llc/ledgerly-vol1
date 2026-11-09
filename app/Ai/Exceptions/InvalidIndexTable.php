<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class InvalidIndexTable extends InvalidArgumentException
{
    public static function name(string $table): self
    {
        return new self("[{$table}] is not a valid index table name.");
    }

    public static function notABenchTable(string $table): self
    {
        return new self("--reindex-to must be a table named bench_<something>, got [{$table}].");
    }
}
