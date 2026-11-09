@extends('layouts.app')

@section('title', 'Invoices · Ledgerly')

@section('content')
    <h1>Invoices</h1>

    <div class="filters">
        <a href="{{ url('invoices') }}" class="{{ $activeStatus ? '' : 'active' }}">All</a>
        @foreach ($statuses as $status)
            <a href="{{ url('invoices') }}?status={{ $status->value }}" class="{{ $activeStatus === $status ? 'active' : '' }}">{{ ucfirst($status->value) }}</a>
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                <th>Number</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Due</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td><a href="{{ url("invoices/{$invoice->id}") }}">{{ $invoice->number }}</a></td>
                    <td><a href="{{ url("customers/{$invoice->customer_id}") }}">{{ $invoice->customer->name }}</a></td>
                    <td><span class="badge {{ $invoice->status->value }}">{{ $invoice->status->value }}</span></td>
                    <td>{{ $invoice->due_at?->toDateString() }}</td>
                    <td class="num">{{ number_format($invoice->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $invoices->links() }}
@endsection
