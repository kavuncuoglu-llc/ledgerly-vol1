<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Ledgerly')</title>
    <link rel="stylesheet" href="{{ asset('css/ledgerly.css') }}">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ url('customers') }}">Ledgerly</a>
        <nav>
            <a href="{{ url('customers') }}">Customers</a>
            <a href="{{ url('invoices') }}">Invoices</a>
            <a href="{{ url('help') }}">Help center</a>
            <a href="{{ url('inbox') }}">Support inbox</a>
        </nav>
    </header>

    <main class="page">
        @yield('content')
    </main>
</body>
</html>
