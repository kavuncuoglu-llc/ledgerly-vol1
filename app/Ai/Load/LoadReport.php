<?php

namespace App\Ai\Load;

final class LoadReport
{
    private const HEADERS = ['concurrency', 'req/s', 'out tok/s', 'TTFT p50', 'TTFT p99', 'ITL p50', 'ITL p99', 'failed'];

    public static function rows(array $results): array
    {
        return array_map(fn (BenchResult $result): array => [
            $result->concurrency,
            number_format($result->requestsPerSecond, 1),
            number_format($result->outputTokensPerSecond),
            self::milliseconds($result->ttftP50),
            self::milliseconds($result->ttftP99),
            self::milliseconds($result->itlP50),
            self::milliseconds($result->itlP99),
            $result->failed,
        ], $results);
    }

    public static function headers(): array
    {
        return self::HEADERS;
    }

    private static function milliseconds(float $value): string
    {
        return number_format($value).' ms';
    }
}
