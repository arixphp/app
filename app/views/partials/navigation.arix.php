<nav aria-label="Navigation">
    <a href="{{ $view->url() }}">{{ $view->translate('nav.home') }}</a>
    <a href="{{ $view->url('/about') }}">{{ $view->translate('nav.about') }}</a>
    @foreach($view->locales() as $language)
        <a hreflang="{{ $language }}" lang="{{ $language }}" href="{{ $view->url($view->currentPath(), $language) }}">{{ strtoupper($language) }}</a>
    @endforeach
</nav>
