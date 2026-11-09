<?php

namespace App\Ai\Hardware;

final class ByteSize
{
    public const BYTES_PER_GIB = 1_073_741_824;

    public static function gib(float|int $bytes): float
    {
        return $bytes / self::BYTES_PER_GIB;
    }

    public static function fromGib(float|int $gib): int
    {
        return (int) round($gib * self::BYTES_PER_GIB);
    }

    public static function format(float|int $bytes): string
    {
        return number_format(self::gib($bytes), 1).' GiB';
    }
}
