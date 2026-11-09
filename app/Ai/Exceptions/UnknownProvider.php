<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnknownProvider extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self("No provider named [{$name}] is configured in config/ai.php.");
    }

    public static function driver(string $name, string $driver): self
    {
        return new self("Provider [{$name}] names the driver [{$driver}], which is not one of the registered drivers.");
    }

    public static function notChat(string $name, string $driver): self
    {
        return new self("Provider [{$name}] uses the driver [{$driver}], which is not a chat driver.");
    }

    public static function notEmbedding(string $name, string $driver): self
    {
        return new self("Provider [{$name}] uses the driver [{$driver}], which is not an embedding driver.");
    }

    public static function notReranker(string $name, string $driver): self
    {
        return new self("Provider [{$name}] uses the driver [{$driver}], which is not a reranker driver.");
    }
}
