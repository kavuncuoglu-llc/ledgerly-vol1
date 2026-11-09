<?php

namespace App\Ai\Doctor;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    public function symbol(): string
    {
        return match ($this) {
            self::Pass => '✓',
            self::Warn => '⚠',
            self::Fail => '✗',
        };
    }
}
