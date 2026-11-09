<?php

namespace App\Ai\Agents;

final class CallSignature
{
    public static function name(array $call): string
    {
        return (string) ($call['function']['name'] ?? '');
    }

    public static function arguments(array $call): array
    {
        $raw = $call['function']['arguments'] ?? [];

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public static function of(array $call): string
    {
        $arguments = self::arguments($call);

        ksort($arguments);

        return self::name($call).':'.sha1((string) json_encode($arguments));
    }

    public static function describe(array $call): string
    {
        $pairs = [];

        foreach (self::arguments($call) as $key => $value) {
            $pairs[] = $key.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return self::name($call).'('.implode(', ', $pairs).')';
    }
}
