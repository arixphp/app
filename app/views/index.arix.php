@extends('layouts.main')

@section('content')
    <p class="eyebrow">PHP · HTML · CSS</p>
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $description }}</p>
    @component('components.card', ['heading' => $view->translate('home.heading')])
        <p>{{ $view->translate('home.content') }}</p>
    @endcomponent
@endsection
