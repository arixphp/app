<?php

declare(strict_types=1);

use App\Controllers\AboutController;
use App\Controllers\WelcomeController;
use Arix\Facades\Route;

Route::get('/', [WelcomeController::class, 'index'])->cache(ttl: 300);
Route::get('/about', [AboutController::class, 'index']);
