<!doctype html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? '' }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="stylesheet" href="{{ asset('styles/app.css') }}?v=3">
    <script type="module" src="{{ asset('scripts/app.js') }}?v=2"></script>
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
