<?php

namespace App\Ai\Drivers\Support;

use Generator;
use Psr\Http\Message\StreamInterface;

final class SseReader
{
    public static function payloads(StreamInterface $stream): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $payload = self::payloadOf(substr($buffer, 0, $newline));

                $buffer = substr($buffer, $newline + 1);

                if ($payload !== '') {
                    yield $payload;
                }
            }
        }

        $remainder = self::payloadOf($buffer);

        if ($remainder !== '') {
            yield $remainder;
        }
    }

    private static function payloadOf(string $line): string
    {
        $line = trim($line);

        if (! str_starts_with($line, 'data:')) {
            return '';
        }

        return trim(substr($line, 5));
    }
}
