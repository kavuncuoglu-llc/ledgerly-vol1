<?php

namespace App\Ai\Embeddings;

final class BoilerplateStripper
{
    public function __construct(private readonly array $patterns) {}

    public function count(): int
    {
        return count($this->patterns);
    }

    public function strip(string $markdown): string
    {
        $stripped = $markdown;

        foreach ($this->patterns as $pattern) {
            $stripped = (string) preg_replace($pattern, '', $stripped);
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $stripped));
    }
}
