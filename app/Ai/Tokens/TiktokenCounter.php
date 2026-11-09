<?php

namespace App\Ai\Tokens;

use Yethee\Tiktoken\EncoderProvider;

final class TiktokenCounter implements TokenCounter
{
    private ?EncoderProvider $encoderProvider = null;

    public function __construct(private string $encoding = 'o200k_base') {}

    public function count(string $text): int
    {
        $encoder = $this->encoderProvider()->get($this->encoding);

        return count($encoder->encode($text));
    }

    private function encoderProvider(): EncoderProvider
    {
        $this->encoderProvider ??= new EncoderProvider;

        return $this->encoderProvider;
    }
}
