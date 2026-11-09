<?php

namespace App\Ai\Native;

final class LlamaEndpoint
{
    public const DRIVER = 'llama-server';

    public static function nativeBaseUrl(string $baseUrl): string
    {
        return rtrim((string) preg_replace('#/v1/?$#', '', $baseUrl), '/');
    }

    public static function isLlamaServer(array $providerConfig): bool
    {
        if (($providerConfig['driver'] ?? '') === self::DRIVER) {
            return true;
        }

        return (bool) ($providerConfig['llama'] ?? false);
    }
}
