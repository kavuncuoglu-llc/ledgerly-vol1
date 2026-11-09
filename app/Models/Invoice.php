<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'customer_id',
    'number',
    'status',
    'currency',
    'subtotal',
    'tax',
    'total',
    'issued_at',
    'due_at',
    'paid_at',
    'recurring_interval',
    'is_disputed',
    'has_payment_plan',
    'notes',
])]
class Invoice extends Model
{
    use HasFactory;

    public const LATE_FEE_GRACE_DAYS = 30;

    public const LATE_FEE_DAYS_PER_MONTH = 30;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'is_disputed' => 'boolean',
            'has_payment_plan' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeWhereYearMonth(Builder $query, string $column, string $yearMonth): Builder
    {
        $month = Carbon::parse($yearMonth.'-01');

        return $query->whereBetween($column, [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
        ]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Overdue);
    }

    public function daysOverdue(): int
    {
        if ($this->status !== InvoiceStatus::Overdue) {
            return 0;
        }

        return (int) $this->due_at->diffInDays(now(), absolute: true);
    }

    public function lateFee(): float
    {
        if ($this->is_disputed) {
            return 0.0;
        }

        if ($this->has_payment_plan) {
            return 0.0;
        }

        if (! $this->customer->late_fee_enabled) {
            return 0.0;
        }

        $chargeableDays = $this->daysOverdue() - self::LATE_FEE_GRACE_DAYS;

        if ($chargeableDays <= 0) {
            return 0.0;
        }

        $monthlyRate = (float) $this->customer->late_fee_percent / 100;

        $fee = (float) $this->total * $monthlyRate * ($chargeableDays / self::LATE_FEE_DAYS_PER_MONTH);

        return round($fee, 2);
    }
}
