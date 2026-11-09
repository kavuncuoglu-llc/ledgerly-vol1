<?php

namespace App\Ai\Schema;

use App\Ai\Exceptions\UnsupportedSchemaProperty;
use App\Ai\Structured\ExtractionMeta;
use BackedEnum;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class SchemaBuilder
{
    private const SCALAR_TYPES = [
        'string' => 'string',
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
    ];

    public static function for(string $class): array
    {
        $parameters = self::schemaParameters($class);

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_map(
                fn (ReflectionParameter $parameter): string => $parameter->getName(),
                $parameters,
            ),
            'properties' => self::properties($class, $parameters),
        ];
    }

    public static function name(string $class): string
    {
        return Str::snake(class_basename($class));
    }

    private static function schemaParameters(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        $parameters = $constructor
            ? $constructor->getParameters()
            : [];

        return array_values(array_filter(
            $parameters,
            fn (ReflectionParameter $parameter): bool => ! self::isMeta($parameter),
        ));
    }

    private static function isMeta(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        return $type->getName() === ExtractionMeta::class;
    }

    private static function properties(string $class, array $parameters): array
    {
        $properties = [];

        foreach ($parameters as $parameter) {
            $properties[$parameter->getName()] = self::property($class, $parameter);
        }

        return $properties;
    }

    private static function property(string $class, ReflectionParameter $parameter): array
    {
        $schema = self::typeSchema($class, $parameter);

        $description = $parameter->getAttributes(Describe::class);

        if ($description === []) {
            return $schema;
        }

        return [
            'type' => $schema['type'],
            'description' => $description[0]->newInstance()->description,
        ] + $schema;
    }

    private static function typeSchema(string $class, ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type === null) {
            throw UnsupportedSchemaProperty::untyped($class, $parameter->getName());
        }

        if (! $type instanceof ReflectionNamedType) {
            throw UnsupportedSchemaProperty::unsupportedType($class, $parameter->getName(), (string) $type);
        }

        $schema = self::namedTypeSchema($class, $parameter, $type);

        if (! $type->allowsNull()) {
            return $schema;
        }

        return self::nullable($schema);
    }

    private static function namedTypeSchema(string $class, ReflectionParameter $parameter, ReflectionNamedType $type): array
    {
        $name = $type->getName();

        if (isset(self::SCALAR_TYPES[$name])) {
            return ['type' => self::SCALAR_TYPES[$name]];
        }

        if ($name === 'array') {
            return self::arraySchema($class, $parameter);
        }

        if (is_subclass_of($name, BackedEnum::class)) {
            return self::enumSchema($name);
        }

        if (class_exists($name)) {
            return self::for($name);
        }

        throw UnsupportedSchemaProperty::unsupportedType($class, $parameter->getName(), $name);
    }

    private static function arraySchema(string $class, ReflectionParameter $parameter): array
    {
        $items = $parameter->getAttributes(ItemsOf::class);

        if ($items === []) {
            throw UnsupportedSchemaProperty::arrayWithoutItems($class, $parameter->getName());
        }

        return [
            'type' => 'array',
            'items' => self::for($items[0]->newInstance()->class),
        ];
    }

    private static function enumSchema(string $enum): array
    {
        $values = array_map(
            fn (BackedEnum $case): int|string => $case->value,
            $enum::cases(),
        );

        $type = is_int($values[0])
            ? 'integer'
            : 'string';

        return [
            'type' => $type,
            'enum' => $values,
        ];
    }

    private static function nullable(array $schema): array
    {
        $schema['type'] = [$schema['type'], 'null'];

        if (isset($schema['enum'])) {
            $schema['enum'][] = null;
        }

        return $schema;
    }
}
