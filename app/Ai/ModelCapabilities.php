<?php

namespace App\Ai;

use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capabilities\SupportsNativeStructuredOutput;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\DecoratesChatModel;
use Closure;

final class ModelCapabilities
{
    public static function has(ChatModel $model, string $capabilityInterface): bool
    {
        return self::innermost($model) instanceof $capabilityInterface;
    }

    public static function withContextWindow(ChatModel $model, int $tokens): ChatModel
    {
        return self::reconfigure(
            $model,
            SupportsContextWindowOverride::class,
            fn (SupportsContextWindowOverride $driver): ChatModel => $driver->withContextWindow($tokens),
        );
    }

    public static function withNativeStructuredOutput(ChatModel $model, bool $enabled = true): ChatModel
    {
        return self::reconfigure(
            $model,
            SupportsNativeStructuredOutput::class,
            fn (SupportsNativeStructuredOutput $driver): ChatModel => $driver->withNativeStructuredOutput($enabled),
        );
    }

    public static function withGrammar(ChatModel $model, string $grammar): ChatModel
    {
        return self::reconfigure(
            $model,
            SupportsGrammar::class,
            fn (SupportsGrammar $driver): ChatModel => $driver->withGrammar($grammar),
        );
    }

    private static function reconfigure(ChatModel $model, string $capabilityInterface, Closure $apply): ChatModel
    {
        if ($model instanceof DecoratesChatModel) {
            return $model->withInner(self::reconfigure($model->inner(), $capabilityInterface, $apply));
        }

        if (! $model instanceof $capabilityInterface) {
            return $model;
        }

        return $apply($model);
    }

    private static function innermost(ChatModel $model): ChatModel
    {
        while ($model instanceof DecoratesChatModel) {
            $model = $model->inner();
        }

        return $model;
    }
}
