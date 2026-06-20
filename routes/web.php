<?php

use App\Http\Controllers\SmartCompanyController;
use App\Http\Controllers\MemberRegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SmartCompanyController::class, 'index'])->name('smart-company.index');
Route::redirect('/erp', '/');
Route::redirect('/dashboard', '/');
Route::get('/member/register/{token}', [MemberRegistrationController::class, 'show'])->name('member-registration.show');
Route::post('/member/register/{token}', [MemberRegistrationController::class, 'store'])->name('member-registration.store');
