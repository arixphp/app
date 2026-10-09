@extends('layouts.main')

@section('content')
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $description }}</p>
    <p>{{ $view->translate('about.content') }}</p>
@endsection
