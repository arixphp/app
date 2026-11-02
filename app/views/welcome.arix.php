@extends('layouts.main')

@section('content')
    <section class="hero" aria-labelledby="home-title">
        <div class="hero-copy">
            <h1 id="home-title">{{ $title }}</h1>
            <p class="lead">{{ $description }}</p>
        </div>
        <figure class="hero-brand">
            <img src="{{ media('arix-combinationmark.webp') }}" alt="ArixPHP logo" width="750" height="500" fetchpriority="high" decoding="async">
        </figure>
    </section>
    @component('components.card', ['heading' => trans('home.heading')])
        <p>{{ trans('home.content') }}</p>
    @endcomponent
@endsection
