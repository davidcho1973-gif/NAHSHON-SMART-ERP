<?php

use App\Http\Controllers\SmartCompanyController;
use App\Http\Controllers\AttendanceAppController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MemberRegistrationController;
use App\Http\Controllers\MobileExpenseController;
use App\Http\Controllers\ExpensePreApprovalController;
use App\Http\Controllers\PayrollController;
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
    Route::get('/vehicle-api/file', [App\Http\Controllers\VehicleApiController::class, 'serveFile'])->name('vehicle.file');

    // Equipment API Routes
    Route::post('/equipment-api/scan-rental', [App\Http\Controllers\EquipmentApiController::class, 'scanRental'])->name('equipment.scan-rental');
    Route::post('/equipment-api/save', [App\Http\Controllers\EquipmentApiController::class, 'saveEquipment'])->name('equipment.save');
    Route::post('/equipment-api/assign', [App\Http\Controllers\EquipmentApiController::class, 'assignEquipment'])->name('equipment.assign');
    Route::post('/equipment-api/return', [App\Http\Controllers\EquipmentApiController::class, 'returnEquipment'])->name('equipment.return');
    Route::get('/equipment-api/{equipment}/history', [App\Http\Controllers\EquipmentApiController::class, 'getRentalHistory'])->name('equipment.history');
    Route::get('/equipment-api/file', [App\Http\Controllers\EquipmentApiController::class, 'serveFile'])->name('equipment.file');
    Route::post('/equipment-api/{equipment}/update', [App\Http\Controllers\EquipmentApiController::class, 'updateEquipment'])->name('equipment.update');
    Route::post('/equipment-api/{equipment}/delete', [App\Http\Controllers\EquipmentApiController::class, 'deleteEquipment'])->name('equipment.delete');


    // Mobile Equipment Routes
    Route::get('/mobile-equipment/index', [\App\Http\Controllers\MobileEquipmentController::class, 'index'])->name('mobile-equipment.index');
    Route::get('/mobile-equipment/wizard', [\App\Http\Controllers\MobileEquipmentController::class, 'wizard'])->name('mobile-equipment.wizard');
    Route::post('/mobile-equipment/scan-photo', [\App\Http\Controllers\MobileEquipmentController::class, 'scanPhoto'])->name('mobile-equipment.scan-photo');
    Route::post('/mobile-equipment/scan-photos-batch', [\App\Http\Controllers\MobileEquipmentController::class, 'scanPhotosBatch'])->name('mobile-equipment.scan-photos-batch');
    Route::post('/mobile-equipment/store', [\App\Http\Controllers\MobileEquipmentController::class, 'store'])->name('mobile-equipment.store');
    Route::post('/mobile-equipment/store-batch', [\App\Http\Controllers\MobileEquipmentController::class, 'storeBatch'])->name('mobile-equipment.store-batch');
    Route::put('/mobile-equipment/{equipment}', [\App\Http\Controllers\MobileEquipmentController::class, 'update'])->name('mobile-equipment.update');
    Route::delete('/mobile-equipment/{equipment}', [\App\Http\Controllers\MobileEquipmentController::class, 'destroy'])->name('mobile-equipment.destroy');

    // Payroll documents (printable payslip + WH-347 certified payroll)
    Route::get('/payroll/run/{run}/certified', [PayrollController::class, 'certified'])->name('payroll.certified');
    Route::get('/payroll/payslip/{payslip}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

    // QR Attendance mobile app
    Route::get('/attendance-app', [AttendanceAppController::class, 'index'])->name('attendance-app.index');
    Route::get('/attendance-app/team/{token}', [AttendanceAppController::class, 'team'])->name('attendance-app.team');
    Route::post('/attendance-app/team/{token}', [AttendanceAppController::class, 'recordTeam'])->name('attendance-app.team.record');
    Route::get('/attendance-app/team/{token}/crew', [AttendanceAppController::class, 'crew'])->name('attendance-app.crew');
    Route::post('/attendance-app/team/{token}/crew', [AttendanceAppController::class, 'recordCrew'])->name('attendance-app.crew.record');
    Route::get('/attendance-app/badge/{token}', [AttendanceAppController::class, 'badge'])->name('attendance-app.badge');
    Route::get('/attendance-app/employee/{employee}/badge-qr', [AttendanceAppController::class, 'employeeBadgeQr'])->name('attendance-app.employee.badge-qr');

    // Team QR Code Printable Sheet
    Route::get('/team/{team}/qr', [SmartCompanyController::class, 'teamQr'])->name('team.qr');

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
