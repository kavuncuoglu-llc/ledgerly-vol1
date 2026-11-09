<?php

namespace App\Ai\Cache;

use App\Enums\TicketCategory;
use Carbon\CarbonImmutable;

final class CachePolicy
{
    private const PERSONAL_WORDS = '/\b(my|mine|myself|our|ours|ourselves|we|we\'re|we\'ve|this|that|these|those|here)\b/i';

    private const ACCOUNT_DETAILS = [
        '/\d/',
        '/@/',
        '/\bINV[-\s]?\d+/i',
        '/\b(january|february|march|april|june|july|august|september|october|november|december)\b/i',
        '/\b(today|tomorrow|yesterday|tonight)\b/i',
    ];

    private const DECLINE_MARKERS = [
        '/support ticket/i',
        '/open a ticket/i',
        '/log a ticket/i',
        '/outside (of )?what i can help/i',
        '/not in the (provided )?context/i',
        '/(don\'t|do not|doesn\'t|does not) (have|contain|include) (that|this|enough|any|the)/i',
        '/\b(not able to|unable to|can\'t|cannot|not sure|no information|sorry)\b/i',
        '/\bnot (about|related to|part of)\b/i',
        '/\boutside\b/i',
    ];

    private const MIN_ANSWER_CHARACTERS = 20;

    public function cacheable(TicketCategory $category): bool
    {
        return array_key_exists($category->value, $this->thresholds());
    }

    public function threshold(TicketCategory $category): float
    {
        return $this->thresholdFor($category->value);
    }

    public function thresholdFor(string $category): float
    {
        return (float) ($this->thresholds()[$category] ?? 1.0);
    }

    public function cacheableCategories(): array
    {
        return array_keys($this->thresholds());
    }

    public function questionIsGeneric(string $question): bool
    {
        if (preg_match(self::PERSONAL_WORDS, $question) === 1) {
            return false;
        }

        return ! $this->containsAccountDetail($question);
    }

    public function containsAccountDetail(string $text): bool
    {
        foreach (self::ACCOUNT_DETAILS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public function answerIsStorable(string $answer): bool
    {
        if (mb_strlen(trim($answer)) < self::MIN_ANSWER_CHARACTERS) {
            return false;
        }

        if (preg_match('/\bINV[-\s]?\d+|\bT-\d{3,}|[\w.+-]+@[\w-]+\.[\w.-]+/i', $answer) === 1) {
            return false;
        }

        foreach (self::DECLINE_MARKERS as $pattern) {
            if (preg_match($pattern, $answer) === 1) {
                return false;
            }
        }

        return true;
    }

    public function requiresGrounding(): bool
    {
        return (bool) config('ai.semantic_cache.require_grounding');
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $days = config('ai.semantic_cache.ttl_days');

        if ($days === null) {
            return null;
        }

        return CarbonImmutable::now()->addDays((int) $days);
    }

    private function thresholds(): array
    {
        return config('ai.semantic_cache.thresholds', []);
    }
}
