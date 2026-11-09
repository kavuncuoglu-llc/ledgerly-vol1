<?php

namespace App\Ai\Hardware;

enum MemoryKind: string
{
    case Unified = 'unified';
    case Discrete = 'discrete';
    case SystemRam = 'system-ram';
    case Unknown = 'unknown';
}
