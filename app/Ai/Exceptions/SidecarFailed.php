<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

final class SidecarFailed extends RuntimeException
{
    public static function unreachable(string $baseUrl, Throwable $previous): self
    {
        return new self("Could not reach the sidecar at {$baseUrl}. Is it running? Start it with: docker compose up -d sidecar", previous: $previous);
    }

    public static function rejected(int $status, string $body): self
    {
        return new self("The sidecar answered HTTP {$status}: {$body}");
    }

    public static function malformed(string $endpoint): self
    {
        return new self("The sidecar answered {$endpoint} with a body of the wrong shape.");
    }

    public static function mismatchedScores(int $expected, int $received): self
    {
        return new self("The sidecar returned {$received} scores for {$expected} passages.");
    }

    public static function invalidUrl(string $url): self
    {
        return new self("ai.sidecar.url must be an absolute http or https URL, got: {$url}");
    }

    public static function fileTooLarge(string $path, int $limitMegabytes): self
    {
        return new self("{$path} is larger than the {$limitMegabytes} MB sidecar upload limit.");
    }

    public static function unreadableFile(string $path): self
    {
        return new self("Could not read {$path} for the sidecar.");
    }
}
