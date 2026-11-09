<?php

namespace App\Ai\Doctor;

final class AiQueueWorkers
{
    private const SUPERVISOR = 'ai-interactive';

    public function count(): int
    {
        $environment = (string) app()->environment();

        $configured = config("horizon.environments.{$environment}.".self::SUPERVISOR.'.processes')
            ?? config('horizon.defaults.'.self::SUPERVISOR.'.processes');

        return (int) $configured;
    }
}
