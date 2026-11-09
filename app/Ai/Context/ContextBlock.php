<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\LabelMap;
use App\Ai\Retrieval\RetrievedChunk;

final class ContextBlock
{
    public static function render(array $chunks): string
    {
        $passages = [];

        foreach (LabelMap::for($chunks) as $label => $chunk) {
            $passages[] = "[{$label}]\n".$chunk->body();
        }

        return self::preamble($chunks)."\n\n".implode("\n\n", $passages);
    }

    private static function preamble(array $chunks): string
    {
        $version = self::versionOf($chunks);

        return "The following passages are from Ledgerly's help center (version {$version}). Answer only from them.\n"
            ."Cite the label of each passage you rely on, in brackets, after the sentence it supports.\n"
            .'If they do not contain the answer, say so.';
    }

    private static function versionOf(array $chunks): string
    {
        $first = $chunks[0] ?? null;

        if (! $first instanceof RetrievedChunk) {
            return 'unknown';
        }

        return $first->helpVersion === ''
            ? 'unknown'
            : $first->helpVersion;
    }
}
