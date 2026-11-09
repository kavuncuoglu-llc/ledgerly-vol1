<?php

namespace App\Ai\Conversations;

use App\Ai\Tools\CompactsResult;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use Illuminate\Support\Str;

final class ToolResultCompactor
{
    private const FALLBACK_SUMMARY_CHARACTERS = 120;

    public function __construct(private readonly ToolRegistry $tools) {}

    public function isCompactable(string $toolName, string $content): bool
    {
        if (! $this->tools->has($toolName)) {
            return false;
        }

        if ($this->tools->get($toolName)->kind() !== ToolKind::Read) {
            return false;
        }

        if ($this->isStub($content)) {
            return false;
        }

        return ! $this->isFailure($content);
    }

    public function stubFor(string $toolName, string $content): string
    {
        return json_encode([
            'compacted' => true,
            'tool' => $toolName,
            'summary' => $this->summaryFor($toolName, $content),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function summaryFor(string $toolName, string $content): string
    {
        $tool = $this->tools->get($toolName);

        if ($tool instanceof CompactsResult) {
            return $tool->compactResult($content);
        }

        $firstLine = Str::before(trim($content), "\n");

        return Str::limit($firstLine, self::FALLBACK_SUMMARY_CHARACTERS);
    }

    private function isStub(string $content): bool
    {
        return str_starts_with($content, '{"compacted":true');
    }

    private function isFailure(string $content): bool
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return false;
        }

        return ($decoded['ok'] ?? true) === false;
    }
}
