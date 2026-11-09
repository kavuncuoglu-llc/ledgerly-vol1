<?php

namespace App\Ai\Doctor;

final class LoopbackHost
{
    public static function is(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if ($host === '::1') {
            return true;
        }

        if ($host === '[::1]') {
            return true;
        }

        return str_starts_with($host, '127.');
    }
}
