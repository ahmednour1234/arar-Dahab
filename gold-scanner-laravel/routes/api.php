<?php

use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

Route::middleware('gs:open')->get('/config/status', [ScannerController::class, 'configStatus']);

Route::middleware('gs:key')->group(function () {
    Route::get('/health', [ScannerController::class, 'health']);
    Route::get('/item/{barcode}', [ScannerController::class, 'item'])->where('barcode', '.*');
    Route::get('/demo/barcodes', [ScannerController::class, 'demoBarcodes']);
    Route::get('/files', [ScannerController::class, 'files']);
    Route::get('/items', [ScannerController::class, 'items']);
    Route::get('/live', [ScannerController::class, 'live']);
    Route::post('/live/export', [ScannerController::class, 'exportLive'])->middleware('throttle:30,1');
    Route::get('/counts', [ScannerController::class, 'listCounts']);
    Route::post('/counts', [ScannerController::class, 'createCount'])->middleware('throttle:30,1');
    Route::get('/counts/{id}/excel', [ScannerController::class, 'countExcel']);
    Route::get('/counts/{id}/with-file/{fileId}', [ScannerController::class, 'countWithFile']);
    Route::post('/counts/{id}/append', [ScannerController::class, 'appendCount'])->middleware('throttle:20,1');
    Route::delete('/counts/{id}', [ScannerController::class, 'deleteCount']);
    Route::get('/files/{id}/download', [ScannerController::class, 'downloadFile'])->where('id', '[0-9a-f]{12}');
    Route::delete('/admin/files/{id}', [ScannerController::class, 'deleteFile'])->where('id', '[0-9a-f]{12}')->middleware('throttle:20,1');
    Route::post('/admin/upload', [ScannerController::class, 'upload'])->middleware('throttle:10,1');
});
