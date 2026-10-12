<nav aria-label="{{ trans('nav.label') }}">
    <div class="nav-pages">
        <a href="{{ url('/') }}" @if(url()->currentPath() === '/') aria-current="page" @endif>{{ trans('nav.home') }}</a>
        <a href="{{ url('/about') }}" @if(url()->currentPath() === '/about') aria-current="page" @endif>{{ trans('nav.about') }}</a>
    </div>
</nav>
