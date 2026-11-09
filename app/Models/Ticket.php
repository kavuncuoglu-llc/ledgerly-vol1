<?php

namespace App\Models;

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'number',
    'customer_id',
    'invoice_id',
    'subject',
    'body',
    'category',
    'status',
    'received_at',
    'resolved_at',
    'idempotency_key',
    'source',
])]
class Ticket extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public static function nextNumber(): string
    {
        $highest = (int) static::query()
            ->selectRaw('max(cast(substr(number, 3) as integer)) as highest')
            ->value('highest');

        return 'T-'.($highest + 1);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
