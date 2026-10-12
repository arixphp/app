<?php

declare(strict_types=1);

use App\Controllers\AboutController;
use App\Controllers\WelcomeController;
use Arix\Facades\Route;

Route::get('/', [WelcomeController::class, 'index']);
Route::get('/about', [AboutController::class, 'index']);
