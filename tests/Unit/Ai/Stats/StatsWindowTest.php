<?php

namespace Tests\Unit\Ai\Stats;

use App\Ai\Exceptions\InvalidStatsWindow;
use App\Ai\Stats\StatsWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatsWindowTest extends TestCase
{
    public function test_since_counts_back_from_now_in_the_given_unit(): void
    {
        $this->travelTo(now());

        $this->assertTrue(StatsWindow::since('90m')->equalTo(now()->subMinutes(90)));
        $this->assertTrue(StatsWindow::since('24h')->equalTo(now()->subHours(24)));
        $this->assertTrue(StatsWindow::since('7d')->equalTo(now()->subDays(7)));
    }

    #[DataProvider('invalidWindows')]
    public function test_since_rejects_anything_that_is_not_a_number_and_a_unit(string $window): void
    {
        $this->expectException(InvalidStatsWindow::class);

        StatsWindow::since($window);
    }

    public static function invalidWindows(): array
    {
        return [
            ['yesterday'],
            ['24'],
            ['h'],
            ['0h'],
            ['2w'],
            [''],
        ];
    }
}
