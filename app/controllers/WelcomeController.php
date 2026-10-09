<?php

declare(strict_types=1);

namespace App\Controllers;

use Arix\Views\Contracts\View;

final class WelcomeController
{
    public function index(): View
    {
        return view('index', [
            'title' => trans('home.title'),
            'description' => trans('home.description'),
        ]);
    }
}
