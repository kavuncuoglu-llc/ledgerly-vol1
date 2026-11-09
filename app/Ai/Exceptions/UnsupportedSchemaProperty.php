<?php

namespace App\Ai\Exceptions;

use LogicException;

final class UnsupportedSchemaProperty extends LogicException
{
    public static function untyped(string $class, string $property): self
    {
        return new self("{$class}::\${$property} has no type, so no schema can be generated for it.");
    }

    public static function unsupportedType(string $class, string $property, string $type): self
    {
        return new self("{$class}::\${$property} has type {$type}, which SchemaBuilder cannot express as JSON Schema.");
    }

    public static function arrayWithoutItems(string $class, string $property): self
    {
        return new self("{$class}::\${$property} is an array without an #[ItemsOf] attribute, so its items have no schema.");
    }
}
