<?php

namespace App\Ai\Parsing;

use App\Ai\Sidecar\SidecarClient;

final class SidecarPdfParser implements DocumentParser
{
    public function __construct(private readonly SidecarClient $client) {}

    public function markdown(string $path): string
    {
        return $this->client->parse($path);
    }
}
