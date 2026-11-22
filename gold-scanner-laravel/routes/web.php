<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->view('scanner')->header('Cache-Control', 'no-store'));
