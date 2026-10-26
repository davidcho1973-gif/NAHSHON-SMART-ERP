<?php

use App\Http\Controllers\CadPartLibraryController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin-api/cad/{drawing}/parts')->whereNumber('drawing')->controller(CadPartLibraryController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store')->middleware('throttle:30,1');
    Route::get('/quantities', 'quantities');
    Route::get('/{part}', 'show')->whereUuid('part');
    Route::delete('/{part}', 'archive')->whereUuid('part')->middleware('throttle:30,1');
});
