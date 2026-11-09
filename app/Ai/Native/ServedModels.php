<?php

namespace App\Ai\Native;

use App\Ai\AiManager;

final class ServedModels
{
    public function __construct(private readonly AiManager $ai) {}

    public function idsFor(string $provider): array
    {
        $body = $this->ai->chat($provider)->raw('GET', '/models');

        return array_values(array_filter(array_map(
            fn (mixed $row): ?string => is_array($row) ? ($row['id'] ?? null) : null,
            $body['data'] ?? [],
        )));
    }
}
