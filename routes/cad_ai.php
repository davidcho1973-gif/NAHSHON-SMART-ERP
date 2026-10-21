<?php

use App\Http\Controllers\CadAiSessionController;
use Illuminate\Support\Facades\Route;

// Loaded by the root CAD web/auth/strong-auth group. No browser token is accepted as OAuth authorization.
Route::prefix('admin-api/cad/ai')->controller(CadAiSessionController::class)->group(function (): void {
    Route::post('/sessions', 'store')->middleware('throttle:10,1');
    Route::put('/sessions/{session}', 'update')->whereUuid('session')->middleware('throttle:30,1');
    Route::get('/sessions/{session}/commands', 'commands')->whereUuid('session')->middleware('throttle:120,1');
    Route::post('/sessions/{session}/commands/{command}/result', 'result')->whereUuid(['session', 'command'])->middleware('throttle:30,1');
    Route::delete('/sessions/{session}', 'destroy')->whereUuid('session')->middleware('throttle:30,1');
});
