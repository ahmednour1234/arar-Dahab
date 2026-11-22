<?php

use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

Route::middleware('gs:open')->get('/config/status', [ScannerController::class, 'configStatus']);

Route::middleware('gs:key')->group(function () {
    Route::get('/health', [ScannerController::class, 'health']);
    Route::get('/item/{barcode}', [ScannerController::class, 'item'])->where('barcode', '.*');
    Route::get('/demo/barcodes', [ScannerController::class, 'demoBarcodes']);
    Route::post('/admin/upload', [ScannerController::class, 'upload'])->middleware('throttle:10,1');
});
