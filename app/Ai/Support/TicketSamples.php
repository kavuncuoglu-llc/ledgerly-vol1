<?php

namespace App\Ai\Support;

use App\Ai\Exceptions\UnreadableTicketSamples;

final class TicketSamples
{
    public static function fromFile(string $path): array
    {
        if (! is_file($path)) {
            throw UnreadableTicketSamples::fileMissing($path);
        }

        $samples = json_decode((string) file_get_contents($path), true);

        if (! is_array($samples)) {
            throw UnreadableTicketSamples::notALabeledList($path);
        }

        foreach ($samples as $sample) {
            self::assertLabeled($sample, $path);
        }

        return array_values($samples);
    }

    private static function assertLabeled(mixed $sample, string $path): void
    {
        if (! is_array($sample)) {
            throw UnreadableTicketSamples::notALabeledList($path);
        }

        if (! is_string($sample['message'] ?? null)) {
            throw UnreadableTicketSamples::notALabeledList($path);
        }

        if (! is_string($sample['label'] ?? null)) {
            throw UnreadableTicketSamples::notALabeledList($path);
        }
    }
}
