<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;
use Illuminate\Support\Carbon;
use Throwable;

final class KeepAliveCheck implements DoctorCheck
{
    private const DEFAULT_SECONDS = 300;

    private const TOLERANCE_SECONDS = 15;

    public function run(DoctorContext $context): array
    {
        $loaded = $context->loaded($context->primaryModel(), $context->roundTrip()->runningAfterCompatible);

        $seconds = $this->secondsUntilUnload((string) ($loaded['expires_at'] ?? ''));

        if ($seconds === null) {
            return [CheckResult::warn('keep-alive could not be read: '.$context->primaryModel().' is not listed by /api/ps after the /v1 round trip (loaded: '.$context->loadedNames($context->roundTrip()->runningAfterCompatible).')')];
        }

        $minutes = number_format($seconds / 60, 1);

        $isDefault = $seconds <= self::DEFAULT_SECONDS + self::TOLERANCE_SECONDS;

        if ($isDefault && $context->appUsesNative()) {
            return [CheckResult::pass('chat requests carry keep_alive '.$this->appKeepAlive($context).'; other /v1 calls (embeddings) still unload after the server default of 5m')];
        }

        if ($isDefault) {
            return [CheckResult::warn("server keep-alive looks like the 5m default (a model used through /v1 unloads {$minutes} minutes after use): every idle gap longer than that reloads it. Set OLLAMA_KEEP_ALIVE=30m, or route Ollama through the native API (AI_OLLAMA_NATIVE=true), which sends keep_alive on each request.")];
        }

        return [CheckResult::pass("server keep-alive is not the 5m default (a model used through /v1 stays loaded {$minutes} minutes)")];
    }

    private function appKeepAlive(DoctorContext $context): string
    {
        return (string) ($context->primaryConfig()['keep_alive'] ?? config('ai.ollama.keep_alive'));
    }

    private function secondsUntilUnload(string $expiresAt): ?int
    {
        if ($expiresAt === '') {
            return null;
        }

        try {
            return max(0, (int) Carbon::now()->diffInSeconds(Carbon::parse($expiresAt), false));
        } catch (Throwable) {
            return null;
        }
    }
}
