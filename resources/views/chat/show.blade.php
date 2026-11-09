@extends('layouts.app')

@section('title', 'Assistant · Ledgerly')

@section('content')
    <h1>Assistant</h1>

    @if ($user)
        <div class="chat">
            <div id="chat-log" class="chat-log" aria-live="polite"></div>

            <form id="chat-form" class="chat-form">
                <input id="chat-input" class="chat-input" type="text" maxlength="2000" placeholder="Ask about an invoice, a late fee, your plan..." autocomplete="off" required>
                <button id="chat-send" class="chat-button" type="submit">Send</button>
            </form>
        </div>

        <script id="chat-config" type="application/json">@json($config)</script>
        <script type="module" src="{{ asset('js/chat.js') }}"></script>
    @else
        <form id="login-form" class="chat-login">
            <p class="meta">Sign in to ask the assistant.</p>
            <input class="chat-input" name="email" type="email" placeholder="Email" autocomplete="username" required>
            <input class="chat-input" name="password" type="password" placeholder="Password" autocomplete="current-password" required>
            <button class="chat-button" type="submit">Sign in</button>
            <p id="login-error" class="chat-warning" hidden>Those credentials do not match our records.</p>
        </form>

        <script type="module" src="{{ asset('js/chat.js') }}"></script>
    @endif
@endsection
