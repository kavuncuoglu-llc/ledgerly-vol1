<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;
use App\Ai\Schema\ItemsOf;
use App\Ai\Structured\ExtractionMeta;
use App\Ai\Structured\StructuredData;

final readonly class ExtractedMemories implements StructuredData
{
    public function __construct(
        #[Describe('Facts the customer stated about themselves; an empty list when there are none')]
        #[ItemsOf(ExtractedMemory::class)]
        public array $facts,
        public ExtractionMeta $meta = new ExtractionMeta,
    ) {}

    public static function fromArray(array $data, ExtractionMeta $meta = new ExtractionMeta): static
    {
        return new self(
            facts: array_map(
                fn (array $fact): ExtractedMemory => ExtractedMemory::fromArray($fact),
                $data['facts'] ?? [],
            ),
            meta: $meta,
        );
    }

    public static function rules(): array
    {
        return [
            'facts' => ['present', 'array', 'max:50'],
            'facts.*.key' => ['required', 'string', 'max:64'],
            'facts.*.value' => ['required', 'string', 'max:500'],
            'facts.*.confidence' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    public static function afterRules(string $sourceText = ''): array
    {
        return [];
    }
}
