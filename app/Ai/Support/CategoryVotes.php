<?php

namespace App\Ai\Support;

use App\Enums\TicketCategory;

final readonly class CategoryVotes
{
    public function __construct(private array $categories) {}

    public function count(): int
    {
        return count($this->categories);
    }

    public function majority(): TicketCategory
    {
        $tally = $this->tally();

        if ($tally === []) {
            return TicketCategory::Other;
        }

        arsort($tally);

        return TicketCategory::from((string) array_key_first($tally));
    }

    public function agreement(): float
    {
        if ($this->categories === []) {
            return 0.0;
        }

        return max($this->tally()) / $this->count();
    }

    public function labels(): array
    {
        return array_keys($this->tally());
    }

    public function values(): array
    {
        return array_map(
            fn (TicketCategory $category): string => $category->value,
            $this->categories,
        );
    }

    private function tally(): array
    {
        return array_count_values($this->values());
    }
}
