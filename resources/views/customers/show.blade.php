@extends('layouts.app')

@section('title', $customer->name . ' · Ledgerly')

@section('content')
    <h1>{{ $customer->name }}</h1>

    <p class="meta">
        {{ ucfirst($customer->plan) }} plan · {{ $customer->email }} · {{ $customer->phone }}
        · late fee {{ $customer->late_fee_enabled ? $customer->late_fee_percent . '% per month' : 'off' }}
    </p>

    <h2>Invoices ({{ $invoices->count() }})</h2>

    <table>
        <thead>
            <tr>
                <th>Number</th>
                <th>Status</th>
                <th>Issued</th>
                <th>Due</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td><a href="{{ url("invoices/{$invoice->id}") }}">{{ $invoice->number }}</a></td>
                    <td><span class="badge {{ $invoice->status->value }}">{{ $invoice->status->value }}</span></td>
                    <td>{{ $invoice->issued_at?->toDateString() }}</td>
                    <td>{{ $invoice->due_at?->toDateString() }}</td>
                    <td class="num">{{ number_format($invoice->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Support tickets ({{ $tickets->count() }})</h2>

    <table>
        <thead>
            <tr>
                <th>Ticket</th>
                <th>Subject</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tickets as $ticket)
                <tr>
                    <td><a href="{{ url("inbox/{$ticket->id}") }}">{{ $ticket->number }}</a></td>
                    <td>{{ $ticket->subject }}</td>
                    <td><span class="badge {{ $ticket->status->value }}">{{ $ticket->status->value }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
