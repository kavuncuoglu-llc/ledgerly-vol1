<?php

namespace App\Ai\Drivers\Support;

final class GeminiSchema
{
    private const REMOVED_KEYS = ['additionalProperties', '$schema', 'strict', 'title', 'default'];

    public static function clean(array $schema): array
    {
        $cleaned = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, self::REMOVED_KEYS, true)) {
                continue;
            }

            if ($key === 'type' && is_array($value)) {
                $cleaned = [...$cleaned, ...self::nullableType($value)];

                continue;
            }

            $cleaned[$key] = self::cleanValue($key, $value);
        }

        return $cleaned;
    }

    private static function cleanValue(int|string $key, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($key === 'properties') {
            return array_map(fn (mixed $property): mixed => is_array($property) ? self::clean($property) : $property, $value);
        }

        if ($key === 'items') {
            return self::clean($value);
        }

        return $value;
    }

    private static function nullableType(array $types): array
    {
        $concrete = array_values(array_filter($types, fn (string $type): bool => $type !== 'null'));

        $schema = [
            'type' => $concrete[0] ?? 'string',
        ];

        if (count($concrete) !== count($types)) {
            $schema['nullable'] = true;
        }

        return $schema;
    }
}
