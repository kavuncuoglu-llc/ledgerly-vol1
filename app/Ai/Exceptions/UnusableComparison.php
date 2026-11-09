<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnusableComparison extends RuntimeException
{
    public static function noProviders(): self
    {
        return new self('Name at least one provider with --providers=local,frontier.');
    }

    public static function notConfigured(string $provider): self
    {
        return new self("Provider [{$provider}] is not configured: it needs a model and credentials (see ai:providers).");
    }

    public static function notAChatProvider(string $provider, string $reason): self
    {
        return new self("Provider [{$provider}] cannot be compared: {$reason}");
    }

    public static function unknownFeature(string $feature, array $available): self
    {
        return new self("No comparison cases for feature [{$feature}]. Available: ".implode(', ', $available).'.');
    }

    public static function goldenNeedsOneFeature(): self
    {
        return new self('--golden applies to one feature; pass --feature= with a single feature name.');
    }

    public static function replayNeedsJudge(): self
    {
        return new self('Recorded requests have no expected answer, so replaying them needs --judge=<provider>.');
    }

    public static function noRecordedRequests(string $feature): self
    {
        return new self("No recorded requests for [{$feature}] that can be replayed. Turn on AI_RECORD_REQUESTS=true, use the app, then run this again (requests that offer tools are skipped).");
    }

    public static function malformedGolden(string $path): self
    {
        return new self("{$path} is not a golden set this feature can read.");
    }

    public static function noCases(string $feature): self
    {
        return new self("No usable cases for [{$feature}].");
    }
}
