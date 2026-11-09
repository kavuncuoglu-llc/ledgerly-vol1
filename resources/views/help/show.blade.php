@extends('layouts.app')

@section('title', $article->title . ' · Ledgerly help')

@section('content')
    <p class="meta"><a href="{{ url('help') }}">Help center</a> › {{ \Illuminate\Support\Str::headline($article->category) }}</p>

    <div class="article">
        {!! $article->html() !!}
    </div>

    <p class="meta">Source: resources/{{ $article->path() }}</p>
@endsection
