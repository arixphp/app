<nav aria-label="{{ trans('nav.label') }}">
    <div class="nav-pages">
        <a href="{{ url('/') }}" @if(url()->current_path() === '/') aria-current="page" @endif>{{ trans('nav.home') }}</a>
        <a href="{{ url('/about') }}" @if(url()->current_path() === '/about') aria-current="page" @endif>{{ trans('nav.about') }}</a>
    </div>
    <div class="nav-languages" role="group" aria-label="{{ trans('nav.languages') }}">
        @foreach(config('app.locales') as $language)
            <a hreflang="{{ $language }}" lang="{{ $language }}" href="{{ url(url()->current_path(), $language) }}" @if(config('app.locale') === $language) aria-current="true" @endif>{{ strtoupper($language) }}</a>
        @endforeach
    </div>
</nav>
