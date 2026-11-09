<?php

namespace App\Ai\Facades;

use App\Ai\AiManager;
use App\Ai\Testing\AiFake;
use App\Ai\Testing\FakeLedger;
use App\Ai\Testing\FakeScript;
use Closure;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;

class Ai extends Facade
{
    public static function fake(): FakeScript
    {
        $ledger = new FakeLedger;

        static::swap(new AiFake(static::$app, $ledger));

        return new FakeScript($ledger);
    }

    public static function assertSent(Closure $callback): void
    {
        $matches = array_filter(
            static::ledger()->sent(),
            fn (array $entry): bool => (bool) $callback($entry['request'], $entry['provider']),
        );

        Assert::assertNotEmpty($matches, 'No chat request matched the assertion.');
    }

    public static function assertNotSent(Closure $callback): void
    {
        $matches = array_filter(
            static::ledger()->sent(),
            fn (array $entry): bool => (bool) $callback($entry['request'], $entry['provider']),
        );

        Assert::assertEmpty($matches, 'A chat request matched an assertion that expected none.');
    }

    public static function assertSentCount(string $feature, int $count): void
    {
        Assert::assertCount($count, static::ledger()->sentFor($feature), "Expected {$count} chat requests for [{$feature}].");
    }

    public static function assertNothingSent(): void
    {
        Assert::assertCount(0, static::ledger()->sent(), 'Chat requests were sent.');
    }

    public static function assertNothingSentTo(string $provider): void
    {
        Assert::assertCount(0, static::ledger()->sentTo($provider), "Chat requests were sent to [{$provider}].");
    }

    protected static function getFacadeAccessor(): string
    {
        return AiManager::class;
    }

    private static function ledger(): FakeLedger
    {
        $root = static::getFacadeRoot();

        Assert::assertInstanceOf(AiFake::class, $root, 'Call Ai::fake() before asserting.');

        return $root->ledger();
    }
}
