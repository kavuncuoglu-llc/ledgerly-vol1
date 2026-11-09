<?php

namespace Tests\Support;

final class EventStreamFrames
{
    public static function event(string $eventType, array $payload): string
    {
        return self::frame([
            ':event-type' => $eventType,
            ':content-type' => 'application/json',
            ':message-type' => 'event',
        ], (string) json_encode((object) $payload));
    }

    public static function exception(string $exceptionType, array $payload): string
    {
        return self::frame([
            ':exception-type' => $exceptionType,
            ':content-type' => 'application/json',
            ':message-type' => 'exception',
        ], (string) json_encode((object) $payload));
    }

    public static function frame(array $headers, string $payload): string
    {
        $headerBytes = '';

        foreach ($headers as $name => $value) {
            $headerBytes .= chr(strlen($name)).$name.chr(7).pack('n', strlen($value)).$value;
        }

        $total = 12 + strlen($headerBytes) + strlen($payload) + 4;

        $prelude = pack('NN', $total, strlen($headerBytes));

        $withPrelude = $prelude.pack('N', crc32($prelude)).$headerBytes.$payload;

        return $withPrelude.pack('N', crc32($withPrelude));
    }
}
