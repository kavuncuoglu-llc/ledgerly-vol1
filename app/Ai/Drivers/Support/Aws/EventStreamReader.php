<?php

namespace App\Ai\Drivers\Support\Aws;

use App\Ai\Exceptions\ModelRequestFailed;
use Generator;
use Psr\Http\Message\StreamInterface;

final class EventStreamReader
{
    private const PRELUDE_BYTES = 12;

    private const TRAILER_BYTES = 4;

    private const HEADER_STRING = 7;

    private const FIXED_HEADER_BYTES = [
        0 => 0,
        1 => 0,
        2 => 1,
        3 => 2,
        4 => 4,
        5 => 8,
        8 => 8,
        9 => 16,
    ];

    public static function messages(StreamInterface $stream): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($frame = self::nextFrame($buffer)) !== null) {
                $buffer = substr($buffer, $frame['length']);

                yield $frame['message'];
            }
        }
    }

    public static function parse(string $bytes): array
    {
        $messages = [];

        while (($frame = self::nextFrame($bytes)) !== null) {
            $bytes = substr($bytes, $frame['length']);

            $messages[] = $frame['message'];
        }

        return $messages;
    }

    private static function nextFrame(string $buffer): ?array
    {
        if (strlen($buffer) < self::PRELUDE_BYTES) {
            return null;
        }

        $prelude = unpack('NtotalLength/NheadersLength/NpreludeCrc', $buffer);

        if (strlen($buffer) < $prelude['totalLength']) {
            return null;
        }

        self::assertChecksums($buffer, $prelude);

        $headers = self::headersOf(substr($buffer, self::PRELUDE_BYTES, $prelude['headersLength']));

        $payloadLength = $prelude['totalLength'] - self::PRELUDE_BYTES - $prelude['headersLength'] - self::TRAILER_BYTES;

        return [
            'length' => $prelude['totalLength'],
            'message' => [
                'headers' => $headers,
                'payload' => substr($buffer, self::PRELUDE_BYTES + $prelude['headersLength'], $payloadLength),
            ],
        ];
    }

    private static function assertChecksums(string $buffer, array $prelude): void
    {
        if (crc32(substr($buffer, 0, 8)) !== $prelude['preludeCrc']) {
            throw new ModelRequestFailed('The model server sent an event-stream frame with a bad prelude checksum.');
        }

        $messageCrc = unpack('N', substr($buffer, $prelude['totalLength'] - self::TRAILER_BYTES, self::TRAILER_BYTES))[1];

        if (crc32(substr($buffer, 0, $prelude['totalLength'] - self::TRAILER_BYTES)) !== $messageCrc) {
            throw new ModelRequestFailed('The model server sent an event-stream frame with a bad message checksum.');
        }
    }

    private static function headersOf(string $block): array
    {
        $headers = [];

        $offset = 0;

        while ($offset < strlen($block)) {
            $nameLength = ord($block[$offset]);

            $name = substr($block, $offset + 1, $nameLength);

            $type = ord($block[$offset + 1 + $nameLength]);

            $offset += 2 + $nameLength;

            if ($type === self::HEADER_STRING) {
                $valueLength = unpack('n', substr($block, $offset, 2))[1];

                $headers[$name] = substr($block, $offset + 2, $valueLength);

                $offset += 2 + $valueLength;

                continue;
            }

            $offset += self::skippedBytes($block, $offset, $type);
        }

        return $headers;
    }

    private static function skippedBytes(string $block, int $offset, int $type): int
    {
        if (isset(self::FIXED_HEADER_BYTES[$type])) {
            return self::FIXED_HEADER_BYTES[$type];
        }

        return 2 + unpack('n', substr($block, $offset, 2))[1];
    }
}
