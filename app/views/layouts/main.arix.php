<!doctype html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? '' }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" href="{{ url('/favicon.ico') }}">
    <link rel="stylesheet" href="{{ versioned_asset('styles/app.css') }}">
    <script type="module" src="{{ versioned_asset('scripts/app.js') }}"></script>
    @if (!empty($_SERVER['FRANKENPHP_HOT_RELOAD']))
        <meta name="frankenphp-hot-reload:url" content="{{ $_SERVER['FRANKENPHP_HOT_RELOAD'] }}">
        <script type="module" src="{{ asset('scripts/arix/dev-reload.js') }}"></script>
    @endif
</head>
<body>
    <a class="skip-link" href="#main-content">{{ trans('nav.skip') }}</a>
    <header>
        <a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
        @include('partials.navigation')
    </header>
    <main id="main-content" tabindex="-1">@yield('content')</main>
    <footer>{{ trans('footer') }}</footer>
</body>
</html>
