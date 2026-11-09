<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class Vector implements CastsAttributes
{
    public static function toLiteral(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        return array_map('floatval', json_decode($value, true));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toLiteral($value);
    }
}
