<?php

declare(strict_types=1);

namespace App\Controllers;

use Arix\Views\Contracts\View;

final class AboutController
{
    public function index(): View
    {
        return view('about', [
            'title' => trans('about.title'),
            'description' => trans('about.description'),
        ]);
    }
}
