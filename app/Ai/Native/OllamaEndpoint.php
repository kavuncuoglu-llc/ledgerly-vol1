<?php

namespace App\Ai\Native;

final class OllamaEndpoint
{
    public const DEFAULT_PORT = 11434;

    public static function nativeBaseUrl(string $baseUrl): string
    {
        return rtrim((string) preg_replace('#/v1/?$#', '', $baseUrl), '/');
    }

    public static function isOllama(array $providerConfig): bool
    {
        if (($providerConfig['driver'] ?? '') === 'ollama') {
            return true;
        }

        if (array_key_exists('ollama', $providerConfig)) {
            return (bool) $providerConfig['ollama'];
        }

        return self::port((string) ($providerConfig['base_url'] ?? '')) === self::DEFAULT_PORT;
    }

    public static function wantsNative(array $providerConfig): bool
    {
        if (! self::isOllama($providerConfig)) {
            return false;
        }

        if ((bool) config('ai.ollama.native')) {
            return true;
        }

        return filled(config('ai.ollama.num_ctx'));
    }

    public static function usesNative(array $providerConfig): bool
    {
        if (($providerConfig['driver'] ?? '') === 'ollama') {
            return true;
        }

        return self::wantsNative($providerConfig);
    }

    public static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '');
    }

    public static function port(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port)
            ? $port
            : 0;
    }
}
