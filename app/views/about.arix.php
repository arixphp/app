@extends('layouts.main')

@section('content')
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $description }}</p>
    <figure class="page-brand">
        <img src="{{ media('arix-emblem.webp') }}" alt="ArixPHP emblem" width="680" height="420" loading="lazy" decoding="async">
    </figure>
    <p>{{ trans('about.content') }}</p>
@endsection
