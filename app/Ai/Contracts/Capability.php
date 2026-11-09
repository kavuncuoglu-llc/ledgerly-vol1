<?php

namespace App\Ai\Contracts;

enum Capability: string
{
    case Tools = 'tools';
    case StructuredOutput = 'structured';
    case Vision = 'vision';
    case CacheBreakpoints = 'cache_breakpoints';
    case Seed = 'seed';
    case Reasoning = 'reasoning';
}
