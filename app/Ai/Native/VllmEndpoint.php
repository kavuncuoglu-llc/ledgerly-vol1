<?php

namespace App\Ai\Native;

final class VllmEndpoint
{
    public const DRIVER = 'vllm';

    public static function nativeBaseUrl(string $baseUrl): string
    {
        return rtrim((string) preg_replace('#/v1/?$#', '', $baseUrl), '/');
    }

    public static function isVllm(array $providerConfig): bool
    {
        if (($providerConfig['driver'] ?? '') === self::DRIVER) {
            return true;
        }

        return (bool) ($providerConfig['vllm'] ?? false);
    }
}
