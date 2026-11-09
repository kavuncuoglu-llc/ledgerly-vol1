<?php

namespace App\Ai\Drivers\Support;

use Generator;
use Psr\Http\Message\StreamInterface;

final class NdjsonReader
{
    public static function lines(StreamInterface $stream): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));

                $buffer = substr($buffer, $newline + 1);

                if ($line !== '') {
                    yield $line;
                }
            }
        }

        $remainder = trim($buffer);

        if ($remainder !== '') {
            yield $remainder;
        }
    }
}
