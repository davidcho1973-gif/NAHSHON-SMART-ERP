<?php

use App\Http\Controllers\SmartCompanyController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SmartCompanyController::class, 'index'])->name('smart-company.index');
Route::redirect('/erp', '/');
Route::redirect('/dashboard', '/');
