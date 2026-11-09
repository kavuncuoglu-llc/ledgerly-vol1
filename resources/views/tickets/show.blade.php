@extends('layouts.app')

@section('title', $ticket->number . ' · Ledgerly')

@section('content')
    <h1>{{ $ticket->number }} · {{ $ticket->subject }}</h1>

    <p class="meta">
        <span class="badge {{ $ticket->status->value }}">{{ $ticket->status->value }}</span>
        · {{ $ticket->category?->value }}
        · {{ $ticket->customer?->name }}
        · received {{ $ticket->received_at->toDayDateTimeString() }}
        @if ($ticket->invoice)
            · <a href="{{ url("invoices/{$ticket->invoice_id}") }}">{{ $ticket->invoice->number }}</a>
        @endif
    </p>

    <div class="card">
        {!! nl2br(e($ticket->body)) !!}
    </div>
@endsection
