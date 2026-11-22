<?php

use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

// Controller (not a closure) so `php artisan route:cache` works.
Route::get('/', [ScannerController::class, 'page']);
Route::get('/help', [ScannerController::class, 'help']);
