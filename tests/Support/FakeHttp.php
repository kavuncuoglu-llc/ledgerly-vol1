<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

final class FakeHttp
{
    private const DROPPED_HEADERS = ['content-length', 'transfer-encoding', 'connection'];

    public static function replay(string $driver, string $fixture): void
    {
        $response = self::load($driver, $fixture)['response'];

        Http::fake([
            '*' => Http::response(
                self::bodyOf($response),
                $response['status'],
                self::headersOf($response['headers'] ?? []),
            ),
        ]);
    }

    public static function load(string $driver, string $fixture): array
    {
        $path = base_path("tests/Fixtures/ai/{$driver}/{$fixture}.json");

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function prompt(string $name): array
    {
        $path = base_path("tests/Fixtures/ai/prompts/{$name}.json");

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function fixtureNames(string $driver): array
    {
        $names = array_map(
            fn (string $path): string => basename($path, '.json'),
            glob(base_path("tests/Fixtures/ai/{$driver}/*.json")) ?: [],
        );

        sort($names);

        return $names;
    }

    private static function bodyOf(array $response): string
    {
        if (isset($response['body_base64'])) {
            return (string) base64_decode($response['body_base64'], true);
        }

        return $response['body'];
    }

    private static function headersOf(array $headers): array
    {
        return array_filter(
            $headers,
            fn (string $name): bool => ! in_array(strtolower($name), self::DROPPED_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
