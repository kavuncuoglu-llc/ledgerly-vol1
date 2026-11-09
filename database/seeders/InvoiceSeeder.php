<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public const INVOICES_PER_CUSTOMER = 15;

    private const FIRST_GENERATED_NUMBER = 3800;

    private const ACME_PAID_NUMBERS = [
        4380,
        4391,
        4402,
        4415,
        4426,
        4445,
        4452,
        4463,
    ];

    private int $nextNumber = self::FIRST_GENERATED_NUMBER;

    public function run(): void
    {
        $acme = Customer::query()
            ->where('email', 'billing@acme.example')
            ->first();

        $this->seedAcme($acme);

        Customer::query()
            ->whereKeyNot($acme->id)
            ->orderBy('id')
            ->each(fn (Customer $customer) => $this->seedGeneratedInvoices($customer));
    }

    private function seedAcme(Customer $acme): void
    {
        $this->invoiceFor($acme, 4438)
            ->overdueBy(41)
            ->amounts(780.00, 40.00)
            ->create();

        $this->invoiceFor($acme, 4471)
            ->overdueBy(18)
            ->amounts(1200.00, 84.50)
            ->create();

        $this->invoiceFor($acme, 4472)
            ->paidDaysAgo(12)
            ->amounts(600.00, 40.00)
            ->create();

        $this->invoiceFor($acme, 4476)
            ->sentDueIn(5)
            ->create();

        $this->invoiceFor($acme, 4478)
            ->sentDueIn(20)
            ->create();

        $this->invoiceFor($acme, 4480)
            ->sentDueIn(12)
            ->amounts(2065.97, 144.78)
            ->create();

        foreach (self::ACME_PAID_NUMBERS as $index => $number) {
            $this->invoiceFor($acme, $number)
                ->paidDaysAgo(25 + ($index * 18))
                ->create();
        }
    }

    private function seedGeneratedInvoices(Customer $customer): void
    {
        for ($count = 0; $count < self::INVOICES_PER_CUSTOMER; $count++) {
            $this->generatedInvoiceFor($customer)->create();
        }
    }

    private function generatedInvoiceFor(Customer $customer): Factory
    {
        $number = $this->takeNextNumber();

        $factory = $this->invoiceFor($customer, $number);

        $roll = fake()->numberBetween(1, 100);

        if ($roll <= 62) {
            return $factory->paidDaysAgo(fake()->numberBetween(3, 240));
        }

        if ($roll <= 76) {
            return $factory->sentDueIn(fake()->numberBetween(1, 28));
        }

        if ($roll <= 87) {
            return $factory->overdueBy(fake()->numberBetween(2, 90));
        }

        if ($roll <= 92) {
            return $factory->overdueBy(fake()->numberBetween(35, 70))->disputed();
        }

        if ($roll <= 97) {
            return $factory->draft();
        }

        return $factory->overdueBy(fake()->numberBetween(5, 40))->void();
    }

    private function invoiceFor(Customer $customer, int $number): Factory
    {
        return Invoice::factory()
            ->for($customer)
            ->state([
                'number' => "INV-{$number}",
                'currency' => $customer->currency,
            ]);
    }

    private function takeNextNumber(): int
    {
        $reserved = array_merge(
            self::ACME_PAID_NUMBERS,
            [
                4438,
                4471,
                4472,
                4476,
                4478,
                4480,
            ],
        );

        while (in_array($this->nextNumber, $reserved, true)) {
            $this->nextNumber++;
        }

        return $this->nextNumber++;
    }
}
