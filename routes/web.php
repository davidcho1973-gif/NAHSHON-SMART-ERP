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
    Route::get('/mobile-expense/receipt/{expense}', [MobileExpenseController::class, 'receipt'])->name('mobile-expense.receipt');
    Route::get('/mobile-expense/{expense}/edit', [MobileExpenseController::class, 'edit'])->name('mobile-expense.edit');
    Route::post('/mobile-expense/upload-receipt', [MobileExpenseController::class, 'uploadReceipt'])->name('mobile-expense.upload-receipt');
    Route::post('/mobile-expense/store', [MobileExpenseController::class, 'store'])->name('mobile-expense.store');
    Route::put('/mobile-expense/{expense}', [MobileExpenseController::class, 'update'])->name('mobile-expense.update');
    Route::delete('/mobile-expense/{expense}', [MobileExpenseController::class, 'destroy'])->name('mobile-expense.destroy');

    // Expense Pre-Approval Routes
    Route::get('/expense-pre-approval/index', [ExpensePreApprovalController::class, 'index'])->name('expense-pre-approval.index');
    Route::get('/expense-pre-approval/create', [ExpensePreApprovalController::class, 'create'])->name('expense-pre-approval.create');
    Route::post('/expense-pre-approval/store', [ExpensePreApprovalController::class, 'store'])->name('expense-pre-approval.store');
    Route::patch('/expense-pre-approval/{expensePreApproval}/approve', [ExpensePreApprovalController::class, 'approve'])->name('expense-pre-approval.approve');
    Route::patch('/expense-pre-approval/{expensePreApproval}/reject', [ExpensePreApprovalController::class, 'reject'])->name('expense-pre-approval.reject');

    // Vehicle API Routes
    Route::post('/vehicle-api/scan-rental', [App\Http\Controllers\VehicleApiController::class, 'scanRental'])->name('vehicle.scan-rental');
    Route::post('/vehicle-api/save', [App\Http\Controllers\VehicleApiController::class, 'saveVehicle'])->name('vehicle.save');
    Route::post('/vehicle-api/assign', [App\Http\Controllers\VehicleApiController::class, 'assignVehicle'])->name('vehicle.assign');
    Route::post('/vehicle-api/return', [App\Http\Controllers\VehicleApiController::class, 'returnVehicle'])->name('vehicle.return');
    Route::get('/vehicle-api/{vehicle}/history', [App\Http\Controllers\VehicleApiController::class, 'getRentalHistory'])->name('vehicle.history');

    // Universal Scanner and Compatibility Adapter Route
    Route::post('/smart-company-api/{method}', \App\Http\Controllers\SmartCompanyApiController::class)
        ->where('method', '[A-Za-z0-9_]+')
        ->name('api.smart-company');
});

Route::get('/member/register/{token}/qr', [MemberRegistrationController::class, 'qr'])->name('member-registration.qr');
Route::get('/member/register/{token}', [MemberRegistrationController::class, 'show'])->name('member-registration.show');
Route::post('/member/register/{token}', [MemberRegistrationController::class, 'store'])->name('member-registration.store');
Route::get('/member/site/{site}/apply/qr', [MemberRegistrationController::class, 'siteQr'])->name('member-registration.site.qr');
Route::get('/member/site/{site}/apply', [MemberRegistrationController::class, 'siteShow'])->name('member-registration.site.show');
Route::post('/member/site/{site}/apply', [MemberRegistrationController::class, 'siteStore'])->name('member-registration.site.store');

Route::get('/debug-logs-sec-53298bfd9a', function() {
    $logPath = storage_path('logs/laravel.log');
    if (file_exists($logPath)) {
        return response()->file($logPath);
    }
    return 'Log file not found';
});

Route::get('/debug-build-sec-53298bfd9a', function () {
    $resourcePath = app_path('Filament/Resources/MemberRegistrations/MemberRegistrationResource.php');
    $resource = is_readable($resourcePath) ? (string) file_get_contents($resourcePath) : '';

    return response()->json([
        'marker' => 'hr-badge-save-diagnostic-v1',
        'checked_at' => now()->toIso8601String(),
        'app_env' => app()->environment(),
        'resource_sha1' => $resource !== '' ? sha1($resource) : null,
        'resource_mtime' => is_readable($resourcePath) ? date('c', (int) filemtime($resourcePath)) : null,
        'member_registration_has_any_keyvalue' => str_contains($resource, 'KeyValue::make'),
        'member_registration_has_badge_keyvalue' => str_contains($resource, "KeyValue::make('badge_analysis_payload')"),
        'member_registration_has_payload_preview' => str_contains($resource, "Textarea::make('payload_preview')"),
        'git_commit' => trim((string) @shell_exec('git rev-parse --short HEAD')),
    ]);
});
