<?php

namespace App\Ai\Tools;

enum ToolKind: string
{
    case Read = 'read';
    case WriteLowRisk = 'write_low_risk';
    case WriteHighRisk = 'write_high_risk';

    public function isRead(): bool
    {
        return $this === self::Read;
    }

    public function isWrite(): bool
    {
        return ! $this->isRead();
    }
}
