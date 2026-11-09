@extends('layouts.app')

@section('title', 'Customers · Ledgerly')

@section('content')
    <h1>Customers</h1>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Plan</th>
                <th>Email</th>
                <th class="num">Invoices</th>
                <th class="num">Overdue</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($customers as $customer)
                <tr>
                    <td><a href="{{ url("customers/{$customer->id}") }}">{{ $customer->name }}</a></td>
                    <td>{{ ucfirst($customer->plan) }}</td>
                    <td class="meta">{{ $customer->email }}</td>
                    <td class="num">{{ $customer->invoices_count }}</td>
                    <td class="num">{{ $customer->overdue_invoices_count }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $customers->links() }}
@endsection
