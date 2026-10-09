<!doctype html>
<html lang="{{ $view->locale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · ArixPHP</title>
    <meta name="description" content="{{ $description ?? '' }}">
    <link rel="canonical" href="{{ $view->url($view->currentPath()) }}">
    @foreach($view->locales() as $language)
        <link rel="alternate" hreflang="{{ $language }}" href="{{ $view->url($view->currentPath(), $language) }}">
    @endforeach
    <link rel="stylesheet" href="{{ $view->asset('styles/app.css') }}?v=2">
    <script src="{{ $view->asset('scripts/htmx.js') }}?v=2.0.11" defer></script>
    <script src="{{ $view->asset('scripts/app.js') }}?v=1" defer></script>
</head>
<body>
    <header>
        <a class="brand" href="{{ $view->url() }}">ArixPHP</a>
        @include('partials.navigation')
    </header>
    <main>@yield('content')</main>
    <footer>{{ $view->translate('footer') }}</footer>
</body>
</html>
