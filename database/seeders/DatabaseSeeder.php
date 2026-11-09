<?php

namespace Database\Seeders;

use App\Support\HelpCenter;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public const FAKER_SEED = 20260101;

    public function run(): void
    {
        fake()->seed(self::FAKER_SEED);

        $this->call([
            CustomerSeeder::class,
            InvoiceSeeder::class,
            TicketSeeder::class,
        ]);

        $articleCount = app(HelpCenter::class)->all()->count();

        $this->command->info("Help center: {$articleCount} articles in resources/help");
    }
}
