<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public const CUSTOMER_COUNT = 40;

    public const ACME_POSITION = 12;

    public function run(): void
    {
        for ($position = 1; $position <= self::CUSTOMER_COUNT; $position++) {
            $this->createCustomerAt($position);
        }

        $this->createSupportStaff();
    }

    private function createCustomerAt(int $position): void
    {
        if ($position === self::ACME_POSITION) {
            $this->createAcme();

            return;
        }

        $customer = Customer::factory()->create();

        User::factory()
            ->forCustomer($customer)
            ->create();
    }

    private function createAcme(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Acme Studios',
            'email' => 'billing@acme.example',
            'phone' => '+1 555 010 4471',
            'plan' => 'pro',
        ]);

        User::factory()
            ->forCustomer($customer)
            ->create([
                'name' => 'Dana Reyes',
                'email' => 'acme@example.com',
            ]);
    }

    private function createSupportStaff(): void
    {
        User::factory()->support()->create([
            'name' => 'Sam Okafor',
            'email' => 'sam@ledgerly.example',
        ]);

        User::factory()->support()->create([
            'name' => 'Priya Nair',
            'email' => 'priya@ledgerly.example',
        ]);
    }
}
