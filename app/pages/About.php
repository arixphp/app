<?php

declare(strict_types=1);

namespace App\Pages;

use Arix\Pages\Attributes\Get;
use Arix\Pages\Contracts\Page;
use Arix\Views\Contracts\View;

#[Get('/about')]
final class About implements Page
{
    public function render(): View
    {
        return view('about', [
            'title' => trans('about.title'),
            'description' => trans('about.description'),
        ]);
    }
}
