<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CompactsResult;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolResult;
use Illuminate\Support\Str;

final class ResultSummary
{
    public const LIMIT = 160;

    public const ERROR_LIMIT = 300;

    public static function of(?Tool $tool, ToolResult $result): string
    {
        if ($result->isPending()) {
            return 'awaiting approval: '.$result->summary;
        }

        if ($result->status === ToolResult::STATUS_DENIED) {
            return 'denied: '.$result->reason;
        }

        if ($result->status === ToolResult::STATUS_DECLINED) {
            return 'declined';
        }

        if (! $result->ok) {
            return Str::limit(self::errorLine($result), self::ERROR_LIMIT);
        }

        return self::okSummary($tool, $result);
    }

    private static function okSummary(?Tool $tool, ToolResult $result): string
    {
        if ($tool instanceof CompactsResult) {
            return Str::limit($tool->compactResult($result->toJson()), self::LIMIT);
        }

        if ($result->isText()) {
            return Str::limit(Str::before(trim($result->text), "\n"), self::LIMIT);
        }

        $pairs = [];

        foreach ($result->data as $key => $value) {
            $pairs[] = $key.': '.(is_scalar($value) ? var_export($value, true) : json_encode($value));
        }

        return Str::limit('ok, '.implode(', ', $pairs), self::LIMIT);
    }

    private static function errorLine(ToolResult $result): string
    {
        $reasons = [];

        foreach ($result->details as $field => $messages) {
            $reasons[] = $field.': '.implode(' ', (array) $messages);
        }

        if ($reasons === []) {
            return 'error: '.$result->error;
        }

        return "error: {$result->error} (".implode('; ', $reasons).')';
    }
}
