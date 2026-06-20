<?php

use App\Http\Controllers\SmartCompanyApiController;
use Illuminate\Support\Facades\Route;

Route::post('/smart-company/{method}', SmartCompanyApiController::class)
    ->where('method', '[A-Za-z0-9_]+')
    ->name('api.smart-company');
