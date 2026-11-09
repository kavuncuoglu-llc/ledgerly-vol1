<?php

namespace App\Ai\Drivers\Support;

final class OptionMapper
{
    public function __construct(
        private readonly string $provider,
        private readonly array $renames = [],
        private readonly array $dropped = [],
    ) {}

    public function map(array $options): array
    {
        $mapped = [];

        foreach ($options as $name => $value) {
            if (in_array($name, $this->dropped, true)) {
                WarnOnce::droppedOption($this->provider, (string) $name);

                continue;
            }

            $mapped[$this->renames[$name] ?? $name] = $value;
        }

        return $mapped;
    }
}
