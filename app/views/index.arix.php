@extends('layouts.main')

@section('content')
    <p class="eyebrow">PHP · HTML · CSS</p>
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $description }}</p>
    @component('components.card', ['heading' => trans('home.heading')])
        <p>{{ trans('home.content') }}</p>
    @endcomponent
@endsection
