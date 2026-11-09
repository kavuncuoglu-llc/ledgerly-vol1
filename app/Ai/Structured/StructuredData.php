<?php

namespace App\Ai\Structured;

interface StructuredData
{
    public static function rules(): array;

    public static function afterRules(string $sourceText = ''): array;

    public static function fromArray(array $data, ExtractionMeta $meta = new ExtractionMeta): static;
}
