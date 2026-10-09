<!doctype html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · ArixPHP</title>
    <meta name="description" content="{{ $description ?? '' }}">
    <link rel="canonical" href="{{ url()->current() }}">
    @foreach(config('app.locales') as $language)
        <link rel="alternate" hreflang="{{ $language }}" href="{{ url(url()->current_path(), $language) }}">
    @endforeach
    <link rel="stylesheet" href="{{ asset('styles/app.css') }}?v=2">
    <script src="{{ asset('scripts/htmx.js') }}?v=2.0.11" defer></script>
    <script src="{{ asset('scripts/app.js') }}?v=1" defer></script>
</head>
<body>
    <header>
        <a class="brand" href="{{ url('/') }}">ArixPHP</a>
        @include('partials.navigation')
    </header>
    <main>@yield('content')</main>
    <footer>{{ trans('footer') }}</footer>
</body>
</html>
