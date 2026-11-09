@extends('layouts.app')

@section('title', 'Support inbox · Ledgerly')

@section('content')
    <h1>Support inbox</h1>

    <table>
        <thead>
            <tr>
                <th>Ticket</th>
                <th>Customer</th>
                <th>Subject</th>
                <th>Category</th>
                <th>Status</th>
                <th>Received</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tickets as $ticket)
                <tr>
                    <td><a href="{{ url("inbox/{$ticket->id}") }}">{{ $ticket->number }}</a></td>
                    <td>{{ $ticket->customer?->name }}</td>
                    <td>{{ $ticket->subject }}</td>
                    <td>{{ $ticket->category?->value }}</td>
                    <td><span class="badge {{ $ticket->status->value }}">{{ $ticket->status->value }}</span></td>
                    <td class="meta">{{ $ticket->received_at->diffForHumans() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $tickets->links() }}
@endsection
