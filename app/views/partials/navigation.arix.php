<nav aria-label="Navigation">
    <a href="{{ url('/') }}">{{ trans('nav.home') }}</a>
    <a href="{{ url('/about') }}">{{ trans('nav.about') }}</a>
    @foreach(config('app.locales') as $language)
        <a hreflang="{{ $language }}" lang="{{ $language }}" href="{{ url(url()->current_path(), $language) }}">{{ strtoupper($language) }}</a>
    @endforeach
</nav>
