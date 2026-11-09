<?php

namespace App\Ai\Native;

final class TritonEndpoint
{
    public const DRIVER = 'triton';

    public static function nativeBaseUrl(string $baseUrl): string
    {
        return rtrim((string) preg_replace('#/v1/?$#', '', $baseUrl), '/');
    }

    public static function isTriton(array $providerConfig): bool
    {
        if (($providerConfig['driver'] ?? '') === self::DRIVER) {
            return true;
        }

        return (bool) ($providerConfig['triton'] ?? false);
    }

    public static function authHeaders(array $providerConfig): array
    {
        $header = (string) ($providerConfig['auth_header'] ?? '');

        $value = (string) ($providerConfig['api_key'] ?? '');

        if ($header === '') {
            return [];
        }

        if ($value === '') {
            return [];
        }

        return [$header => $value];
    }
}
