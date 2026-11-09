@extends('layouts.app')

@section('title', $invoice->number . ' · Ledgerly')

@section('content')
    <h1>{{ $invoice->number }} <span class="badge {{ $invoice->status->value }}">{{ $invoice->status->value }}</span></h1>

    <p class="meta">
        <a href="{{ url("customers/{$invoice->customer_id}") }}">{{ $invoice->customer->name }}</a>
        · issued {{ $invoice->issued_at?->toDateString() ?? 'not yet' }}
        · due {{ $invoice->due_at?->toDateString() ?? 'not set' }}
        @if ($invoice->daysOverdue() > 0)
            · {{ $invoice->daysOverdue() }} days overdue
        @endif
    </p>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" class="num">Subtotal</td>
                <td class="num">{{ number_format($invoice->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td colspan="3" class="num">Tax</td>
                <td class="num">{{ number_format($invoice->tax, 2) }}</td>
            </tr>
            <tr>
                <td colspan="3" class="num"><strong>Total ({{ $invoice->currency }})</strong></td>
                <td class="num"><strong>{{ number_format($invoice->total, 2) }}</strong></td>
            </tr>
            @if ($invoice->lateFee() > 0)
                <tr>
                    <td colspan="3" class="num">Late fee so far</td>
                    <td class="num">{{ number_format($invoice->lateFee(), 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($invoice->is_disputed)
        <p class="meta">Marked as disputed: reminders and late fees are paused.</p>
    @endif

    <h2>Payments</h2>

    @if ($invoice->payments->isEmpty())
        <p class="meta">No payments recorded.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Received</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->received_at->toDateString() }}</td>
                        <td>{{ str_replace('_', ' ', $payment->method) }}</td>
                        <td class="meta">{{ $payment->reference }}</td>
                        <td class="num">{{ number_format($payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
