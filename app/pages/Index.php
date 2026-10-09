<?php

declare(strict_types=1);

namespace App\Pages;

use Arix\Pages\Attributes\Cache;
use Arix\Pages\Attributes\Get;
use Arix\Pages\Contracts\Page;
use Arix\Views\Contracts\View;

#[Get('/')]
#[Cache(ttl: 300)]
final class Index implements Page
{
    public function render(): View
    {
        return view('index', [
            'title' => trans('home.title'),
            'description' => trans('home.description'),
        ]);
    }
}
