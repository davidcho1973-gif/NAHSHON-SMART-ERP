<?php

use App\Http\Controllers\SmartCompanyController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MemberRegistrationController;
use App\Http\Controllers\MobileExpenseController;
use App\Http\Controllers\ExpensePreApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [GoogleAuthController::class, 'login'])->name('login');
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleAuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [SmartCompanyController::class, 'index'])->name('smart-company.index');
    Route::redirect('/erp', '/');
    Route::redirect('/dashboard', '/');

    // Mobile Expense Routes
    Route::get('/mobile-expense/index', [MobileExpenseController::class, 'index'])->name('mobile-expense.index');
    Route::get('/mobile-expense/wizard-ai', [MobileExpenseController::class, 'wizard'])->name('mobile-expense.wizard');
    Route::post('/mobile-expense/upload-receipt', [MobileExpenseController::class, 'uploadReceipt'])->name('mobile-expense.upload-receipt');
    Route::post('/mobile-expense/store', [MobileExpenseController::class, 'store'])->name('mobile-expense.store');

    // Expense Pre-Approval Routes
    Route::get('/expense-pre-approval/index', [ExpensePreApprovalController::class, 'index'])->name('expense-pre-approval.index');
    Route::get('/expense-pre-approval/create', [ExpensePreApprovalController::class, 'create'])->name('expense-pre-approval.create');
    Route::post('/expense-pre-approval/store', [ExpensePreApprovalController::class, 'store'])->name('expense-pre-approval.store');

    // Universal Scanner and Compatibility Adapter Route
    Route::post('/smart-company-api/{method}', \App\Http\Controllers\SmartCompanyApiController::class)
        ->where('method', '[A-Za-z0-9_]+')
        ->name('api.smart-company');
});

Route::get('/member/register/{token}/qr', [MemberRegistrationController::class, 'qr'])->name('member-registration.qr');
Route::get('/member/register/{token}', [MemberRegistrationController::class, 'show'])->name('member-registration.show');
Route::post('/member/register/{token}', [MemberRegistrationController::class, 'store'])->name('member-registration.store');

Route::get('/debug-logs-sec-53298bfd9a', function() {
    $logPath = storage_path('logs/laravel.log');
    if (file_exists($logPath)) {
        return response()->file($logPath);
    }
    return 'Log file not found';
});
