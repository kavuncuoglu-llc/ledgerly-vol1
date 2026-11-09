<?php

namespace App\Ai\Retrieval;

use App\Ai\Exceptions\UnreadableGoldenSet;
use Illuminate\Support\Facades\File;

final class GoldenSet
{
    public static function fromFile(string $path): array
    {
        if (! File::exists($path)) {
            throw UnreadableGoldenSet::missing($path);
        }

        $questions = [];

        foreach (explode("\n", File::get($path)) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $questions[] = self::questionFrom($path, $number + 1, $line);
        }

        if ($questions === []) {
            throw UnreadableGoldenSet::empty($path);
        }

        return $questions;
    }

    private static function questionFrom(string $path, int $lineNumber, string $line): GoldenQuestion
    {
        $row = json_decode($line, true);

        if (! is_array($row)) {
            throw UnreadableGoldenSet::invalidLine($path, $lineNumber);
        }

        if (! isset($row['id'], $row['question'], $row['chunks']) || ! is_array($row['chunks'])) {
            throw UnreadableGoldenSet::invalidLine($path, $lineNumber);
        }

        return new GoldenQuestion(
            id: (int) $row['id'],
            question: (string) $row['question'],
            chunks: $row['chunks'],
        );
    }
}
