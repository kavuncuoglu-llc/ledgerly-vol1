<?php

namespace App\Ai\Testing;

final class JsonSchemaCheck
{
    public static function violations(mixed $data, array $schema, string $path = '$'): array
    {
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! self::matchesAnyType($data, $types)) {
            return ["{$path} is not of type ".implode('|', $types)];
        }

        $violations = [];

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $violations[] = "{$path} is not one of the allowed values";
        }

        if (is_array($data) && isset($schema['properties'])) {
            $violations = [...$violations, ...self::objectViolations($data, $schema, $path)];
        }

        if (is_array($data) && isset($schema['items'])) {
            $violations = [...$violations, ...self::itemViolations($data, $schema['items'], $path)];
        }

        return $violations;
    }

    private static function objectViolations(array $data, array $schema, string $path): array
    {
        $violations = [];

        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $data)) {
                $violations[] = "{$path}.{$required} is required";
            }
        }

        foreach ($data as $key => $value) {
            if (isset($schema['properties'][$key])) {
                $violations = [...$violations, ...self::violations($value, $schema['properties'][$key], "{$path}.{$key}")];

                continue;
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                $violations[] = "{$path}.{$key} is not allowed";
            }
        }

        return $violations;
    }

    private static function itemViolations(array $data, array $items, string $path): array
    {
        $violations = [];

        foreach (array_values($data) as $index => $value) {
            $violations = [...$violations, ...self::violations($value, $items, "{$path}[{$index}]")];
        }

        return $violations;
    }

    private static function matchesAnyType(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            if (self::matchesType($data, $type)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesType(mixed $data, string $type): bool
    {
        return match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
            default => true,
        };
    }
}
