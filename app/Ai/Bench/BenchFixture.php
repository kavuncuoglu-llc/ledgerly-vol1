<?php

namespace App\Ai\Bench;

use App\Ai\Exceptions\UnusableBenchmark;
use App\Models\Customer;
use App\Models\Invoice;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class BenchFixture
{
    private const LATE_FEE_PERCENT = 1.5;

    private function __construct(
        public readonly Carbon $today,
        public readonly Collection $invoices,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw UnusableBenchmark::fixtureMissing($path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw UnusableBenchmark::fixtureMalformed($path);
        }

        if (! isset($decoded['today'], $decoded['invoices'])) {
            throw UnusableBenchmark::fixtureMalformed($path);
        }

        return new self(
            today: Carbon::parse($decoded['today'])->startOfDay(),
            invoices: self::invoicesFrom($decoded['invoices']),
        );
    }

    public function first(int $rows): Collection
    {
        return $this->invoices->take($rows)->values();
    }

    public function within(Closure $work): mixed
    {
        $previous = Carbon::getTestNow();

        Carbon::setTestNow($this->today);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    private static function invoicesFrom(array $rows): Collection
    {
        $customer = (new Customer)->forceFill([
            'late_fee_enabled' => true,
            'late_fee_percent' => self::LATE_FEE_PERCENT,
        ]);

        return collect($rows)
            ->map(fn (array $row): Invoice => self::invoiceFrom($row, $customer))
            ->values();
    }

    private static function invoiceFrom(array $row, Customer $customer): Invoice
    {
        $invoice = (new Invoice)->forceFill([
            ...$row,
            'subtotal' => $row['total'],
            'tax' => 0,
            'is_disputed' => false,
            'has_payment_plan' => false,
        ]);

        return $invoice->setRelation('customer', $customer);
    }
}
