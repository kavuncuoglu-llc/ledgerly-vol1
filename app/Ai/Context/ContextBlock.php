<?php

namespace App\Ai\Context;

final class ContextBlock
{
    public static function render(array $chunks): string
    {
        $passages = array_map(
            fn (array $chunk): string => "[{$chunk['source']}] {$chunk['content']}",
            $chunks,
        );

        return "Context:\n".implode("\n\n", $passages);
    }
}
