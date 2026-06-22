<?php

use App\Http\Controllers\SmartCompanyApiController;
use Illuminate\Support\Facades\Route;

Route::post('/smart-company/{method}', SmartCompanyApiController::class)
    ->middleware('web')
    ->where('method', '[A-Za-z0-9_]+')
    ->name('api.smart-company');
