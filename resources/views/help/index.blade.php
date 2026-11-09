@extends('layouts.app')

@section('title', 'Help center · Ledgerly')

@section('content')
    <h1>Help center</h1>

    <p class="meta">{{ $categories->flatten()->count() }} articles in {{ $categories->count() }} categories.</p>

    <div class="grid">
        @foreach ($categories as $category => $articles)
            <div class="card">
                <h2>{{ \Illuminate\Support\Str::headline($category) }}</h2>
                <ul class="plain">
                    @foreach ($articles as $article)
                        <li><a href="{{ url("help/{$article->category}/{$article->slug}") }}">{{ $article->title }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endsection
