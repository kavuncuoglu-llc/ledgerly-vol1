<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class InvoiceFactory extends Factory
{
    private const LINE_DESCRIPTIONS = [
        'Website design',
        'Logo and brand refresh',
        'Monthly retainer',
        'Consulting hours',
        'Photography session',
        'Copywriting',
        'Bookkeeping services',
        'Hosting and maintenance',
        'Video editing',
        'Workshop facilitation',
        'Print materials',
        'Strategy session',
    ];

    private const TAX_RATE = 0.07;

    public function definition(): array
    {
        $subtotal = fake()->randomElement([
            180.00,
            320.00,
            450.00,
            640.00,
            800.00,
            1200.00,
            1850.00,
            2400.00,
            3600.00,
        ]);

        $tax = round($subtotal * self::TAX_RATE, 2);

        $issuedAt = now()->subDays(fake()->numberBetween(1, 60))->setTime(9, 14);

        return [
            'customer_id' => Customer::factory(),
            'number' => 'INV-'.fake()->unique()->numberBetween(10000, 99999),
            'status' => InvoiceStatus::Sent,
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
            'issued_at' => $issuedAt,
            'due_at' => $issuedAt->copy()->addDays(30)->startOfDay(),
            'paid_at' => null,
            'recurring_interval' => null,
            'is_disputed' => false,
            'has_payment_plan' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            $this->createItems($invoice);
            $this->createPayment($invoice);
        });
    }

    public function amounts(float $subtotal, float $tax): static
    {
        return $this->state(fn (array $attributes) => [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Draft,
            'issued_at' => null,
            'due_at' => null,
            'paid_at' => null,
        ]);
    }

    public function sentDueIn(int $days): static
    {
        $dueAt = now()->addDays($days)->startOfDay();

        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Sent,
            'issued_at' => $dueAt->copy()->subDays(30)->setTime(9, 14),
            'due_at' => $dueAt,
            'paid_at' => null,
        ]);
    }

    public function overdueBy(int $days): static
    {
        $dueAt = now()->subDays($days)->startOfDay();

        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Overdue,
            'issued_at' => $dueAt->copy()->subDays(30)->setTime(9, 14),
            'due_at' => $dueAt,
            'paid_at' => null,
        ]);
    }

    public function paidDaysAgo(int $days): static
    {
        $paidAt = now()->subDays($days)->setTime(14, 5);

        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'issued_at' => $paidAt->copy()->subDays(12)->setTime(9, 14),
            'due_at' => $paidAt->copy()->addDays(18)->startOfDay(),
            'paid_at' => $paidAt,
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Void,
            'paid_at' => null,
        ]);
    }

    public function disputed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_disputed' => true,
        ]);
    }

    public function recurring(string $interval): static
    {
        return $this->state(fn (array $attributes) => [
            'recurring_interval' => $interval,
        ]);
    }

    private function createItems(Invoice $invoice): void
    {
        $lineCount = fake()->numberBetween(1, 3);

        $remaining = (float) $invoice->subtotal;

        for ($line = 1; $line <= $lineCount; $line++) {
            $amount = $line === $lineCount
                ? $remaining
                : round($remaining / 2, 2);

            $remaining = round($remaining - $amount, 2);

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => fake()->randomElement(self::LINE_DESCRIPTIONS),
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
            ]);
        }
    }

    private function createPayment(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            return;
        }

        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'method' => fake()->randomElement([
                'card',
                'card',
                'bank_transfer',
                'check',
            ]),
            'reference' => 'pay_'.fake()->bothify('????########'),
            'received_at' => $invoice->paid_at ?? Carbon::now(),
        ]);
    }
}
