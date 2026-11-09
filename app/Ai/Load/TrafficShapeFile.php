<?php

namespace App\Ai\Load;

use App\Ai\Exceptions\UnusableLoadTest;

final class TrafficShapeFile
{
    public static function write(string $path, array $shapes): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $lines = array_map(
            fn (TrafficShape $shape): string => (string) json_encode($shape->toArray()),
            $shapes,
        );

        file_put_contents($path, implode("\n", $lines)."\n");
    }

    public static function read(string $path): array
    {
        if (! is_file($path)) {
            throw UnusableLoadTest::shapeFileMissing($path);
        }

        $shapes = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $shapes[] = self::shapeOf($line, $path);
        }

        if ($shapes === []) {
            throw UnusableLoadTest::shapeFileUnreadable($path);
        }

        return $shapes;
    }

    private static function shapeOf(string $line, string $path): TrafficShape
    {
        $row = json_decode($line, true);

        if (! is_array($row)) {
            throw UnusableLoadTest::shapeFileUnreadable($path);
        }

        if (! isset($row['input_tokens'], $row['output_tokens'])) {
            throw UnusableLoadTest::shapeFileUnreadable($path);
        }

        return TrafficShape::fromArray($row);
    }
}
