<?php

use App\Http\Controllers\SmartCompanyController;
use App\Http\Controllers\AttendanceAppController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\DocumentIntelligenceController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MemberRegistrationController;
use App\Http\Controllers\MobileExpenseController;
use App\Http\Controllers\ExpensePreApprovalController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProjectContractDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [GoogleAuthController::class, 'login'])->name('login');
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleAuthController::class, 'logout'])->name('logout')->middleware('auth');



Route::get('/debug-routes-sec', function () {
    $routes = collect(Route::getRoutes())->map(fn ($r) => [
        'uri' => $r->uri(),
        'methods' => $r->methods(),
        'name' => $r->getName(),
    ]);
    return response()->json([
        'total' => $routes->count(),
        'has_field_app' => $routes->pluck('uri')->contains('field-app'),
        'field_app_routes' => $routes->filter(fn ($r) => str_contains($r['uri'], 'field-app'))->values(),
        'sample_routes' => $routes->pluck('uri')->take(30),
    ]);
});
Route::get('/daily-work-report', [\App\Http\Controllers\DailyWorkReportController::class, 'index'])->name('daily-work-report.index');
Route::post('/daily-work-report/store', [\App\Http\Controllers\DailyWorkReportController::class, 'store'])->name('daily-work-report.store');
Route::get('/field-app', function () {
    return view('field-app.index');
})->name('field-app.index');
Route::get('/field-app/{any}', function () {
    return view('field-app.index');
})->where('any', '.*');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [SmartCompanyController::class, 'index'])->name('smart-company.index');
    Route::redirect('/erp', '/');
    Route::redirect('/dashboard', '/');

    Route::post('/company/switch', [CompanySwitchController::class, '__invoke'])->name('company.switch');

    // Mobile Expense Routes
    Route::get('/mobile-expense/index', [MobileExpenseController::class, 'index'])->name('mobile-expense.index');
    Route::get('/mobile-expense/wizard-ai', [MobileExpenseController::class, 'wizard'])->name('mobile-expense.wizard');
    Route::get('/mobile-expense/receipt/{expense}', [MobileExpenseController::class, 'receipt'])->name('mobile-expense.receipt');
    Route::get('/mobile-expense/{expense}/edit', [MobileExpenseController::class, 'edit'])->name('mobile-expense.edit');
    Route::post('/mobile-expense/upload-receipt', [MobileExpenseController::class, 'uploadReceipt'])->name('mobile-expense.upload-receipt');
    Route::post('/mobile-expense/store', [MobileExpenseController::class, 'store'])->name('mobile-expense.store');
    Route::put('/mobile-expense/{expense}', [MobileExpenseController::class, 'update'])->name('mobile-expense.update');
    Route::patch('/mobile-expense/{expense}/review', [MobileExpenseController::class, 'review'])->name('mobile-expense.review');
    Route::delete('/mobile-expense/{expense}', [MobileExpenseController::class, 'destroy'])->name('mobile-expense.destroy');

    // Expense Pre-Approval Routes
    Route::get('/expense-pre-approval/index', [ExpensePreApprovalController::class, 'index'])->name('expense-pre-approval.index');
    Route::get('/expense-pre-approval/create', [ExpensePreApprovalController::class, 'create'])->name('expense-pre-approval.create');
    Route::post('/expense-pre-approval/store', [ExpensePreApprovalController::class, 'store'])->name('expense-pre-approval.store');
    Route::patch('/expense-pre-approval/{expensePreApproval}/approve', [ExpensePreApprovalController::class, 'approve'])->name('expense-pre-approval.approve');
    Route::patch('/expense-pre-approval/{expensePreApproval}/reject', [ExpensePreApprovalController::class, 'reject'])->name('expense-pre-approval.reject');

    // WBS 공정 — AI 매뉴얼 분석 (업로드 → 분석, 분석된 매뉴얼 리스트)
    Route::post('/wbs-api/upload-manual', [App\Http\Controllers\WbsManualController::class, 'upload'])->name('wbs-manual.upload');
    Route::get('/wbs-api/manuals', [App\Http\Controllers\WbsManualController::class, 'index'])->name('wbs-manual.index');
    Route::get('/wbs-api/manual/{manual}', [App\Http\Controllers\WbsManualController::class, 'show'])->name('wbs-manual.show');

    // 문서통합관리 — 업로드(멀티파트) → AI 자동분석(백그라운드) → 상태 폴링 → 원본 열람
    Route::post('/docs-api/upload', [App\Http\Controllers\IntegratedDocumentController::class, 'upload'])->name('docs.upload');
    Route::get('/docs-api/status', [App\Http\Controllers\IntegratedDocumentController::class, 'status'])->name('docs.status');
    Route::get('/docs-api/file/{document}', [App\Http\Controllers\IntegratedDocumentController::class, 'show'])->name('docs.show');

    // 조달 관리 — 발주서/선적서 AI 분석(업로드 → 추출·단계 판정) + 근거 서류 열람
    Route::post('/procurement-api/analyze', [App\Http\Controllers\ProcurementController::class, 'analyze'])->name('procurement.analyze');
    Route::get('/procurement-api/file/{item}', [App\Http\Controllers\ProcurementController::class, 'showFile'])->name('procurement.file');

    // 하이브리드 자동 출퇴근 — 작업자 앱이 위치/WiFi 신호 전송 + 현재 상태 조회
    Route::post('/attendance-geo/ping', [App\Http\Controllers\AttendanceGeoController::class, 'ping'])->name('attendance-geo.ping');
    Route::get('/attendance-geo/status', [App\Http\Controllers\AttendanceGeoController::class, 'status'])->name('attendance-geo.status');

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
    Route::post('/equipment-api/scan-inventory', [App\Http\Controllers\EquipmentApiController::class, 'scanInventory'])->name('equipment.scan-inventory');
    Route::post('/equipment-api/save-inventory', [App\Http\Controllers\EquipmentApiController::class, 'saveInventory'])->name('equipment.save-inventory');
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

    // 관리자 화면의 파일 업로드(multipart) — SPA 의 JSON API 로는 파일을 실을 수 없다.
    Route::post('/admin-api/contracts/{contract}/documents', [App\Http\Controllers\AdminUploadController::class, 'contractDocument'])
        ->name('admin.contract-document.upload');
    Route::post('/admin-api/applicants/{applicant}/badge-photo', [App\Http\Controllers\AdminUploadController::class, 'applicantBadgePhoto'])
        ->name('admin.applicant-badge-photo.upload');

    // Private contract files — authenticated and access-scope checked before download.
    Route::get('/contracts/documents/{document}/download', [ProjectContractDocumentController::class, 'download'])
        ->name('project-contract-document.download');

    // AI construction document intelligence hub — private originals, search index and preventive actions.
    Route::get('/document-hub', [DocumentIntelligenceController::class, 'index'])->name('document-intelligence.index');
    Route::get('/document-hub/api/documents', [DocumentIntelligenceController::class, 'documents'])->name('document-intelligence.documents');
    Route::post('/document-hub/api/upload', [DocumentIntelligenceController::class, 'upload'])->name('document-intelligence.upload');
    Route::get('/document-hub/api/index.csv', [DocumentIntelligenceController::class, 'exportIndex'])->name('document-intelligence.export-index');
    Route::get('/document-hub/api/documents/{document}', [DocumentIntelligenceController::class, 'show'])->name('document-intelligence.show');
    Route::post('/document-hub/api/documents/{document}/reanalyze', [DocumentIntelligenceController::class, 'reanalyze'])->name('document-intelligence.reanalyze');
    Route::patch('/document-hub/api/documents/{document}/review', [DocumentIntelligenceController::class, 'review'])->name('document-intelligence.review');
    Route::patch('/document-hub/api/actions/{action}', [DocumentIntelligenceController::class, 'updateAction'])->name('document-intelligence.action.update');
    Route::get('/document-hub/documents/{document}/download', [DocumentIntelligenceController::class, 'download'])->name('document-intelligence.download');
    Route::get('/document-hub/documents/{document}/preview', [DocumentIntelligenceController::class, 'preview'])->name('document-intelligence.preview');

    // QR Attendance mobile app
    Route::get('/attendance-app', [AttendanceAppController::class, 'index'])->name('attendance-app.index');
    // 상황실 사진 업로드 — 한 요청에 한 장씩(본문이 작아 크기 제한이 사실상 사라진다)
    Route::post('/ops-api/photo', [\App\Http\Controllers\OpsPhotoController::class, 'store'])->name('ops.photo');

    // 모바일 현장 상황실 — 원문 기록 보기·올리기·수정·삭제
    Route::get('/attendance-app/ops-room', [\App\Http\Controllers\MobileOpsRoomController::class, 'index'])->name('attendance-app.ops-room');
    Route::get('/attendance-app/messages', [CommunicationController::class, 'index'])->name('communication.index');
    Route::post('/attendance-app/messages/direct', [CommunicationController::class, 'startDirect'])->name('communication.direct.start');
    Route::post('/attendance-app/messages/notifications/read', [CommunicationController::class, 'readNotifications'])->name('communication.notifications.read');
    Route::get('/attendance-app/messages/{room}', [CommunicationController::class, 'show'])->name('communication.show');
    Route::post('/attendance-app/messages/{room}', [CommunicationController::class, 'store'])->name('communication.store');
    Route::get('/attendance-app/team/{token}', [AttendanceAppController::class, 'team'])->name('attendance-app.team');
    Route::post('/attendance-app/team/{token}', [AttendanceAppController::class, 'recordTeam'])->name('attendance-app.team.record');
    Route::get('/attendance-app/team/{token}/crew', [AttendanceAppController::class, 'crew'])->name('attendance-app.crew');
    Route::post('/attendance-app/team/{token}/crew', [AttendanceAppController::class, 'recordCrew'])->name('attendance-app.crew.record');
    Route::post('/attendance-app/team/{token}/crew/daily-close', [AttendanceAppController::class, 'closeCrewDay'])->name('attendance-app.crew.daily-close');
    Route::get('/attendance-app/badge/{token}', [AttendanceAppController::class, 'badge'])->name('attendance-app.badge');
    Route::get('/attendance-app/employee/{employee}/badge-qr', [AttendanceAppController::class, 'employeeBadgeQr'])->name('attendance-app.employee.badge-qr');

    // HR daily attendance status report — styled Excel (.xlsx) export
    Route::get('/hr/attendance/export', \App\Http\Controllers\HrAttendanceExportController::class . '@export')
        ->name('hr.attendance.export');

    // Team QR Code Printable Sheet
    Route::get('/team/{team}/qr', [SmartCompanyController::class, 'teamQr'])->name('team.qr');

    // Universal Scanner and Compatibility Adapter Route
    Route::post('/smart-company-api/{method}', \App\Http\Controllers\SmartCompanyApiController::class)
        ->where('method', '[A-Za-z0-9_]+')
        ->name('api.smart-company');
});

// 현장 QR 모아 인쇄 — 게이트·간편등록(직접/협력사)·입사지원서 포스터를 한 번에 출력
Route::get('/print/qr/{site}', [App\Http\Controllers\QrPrintController::class, 'sheet'])
    ->middleware(['auth'])
    ->name('qr-print.sheet');

// 간편 작업자 등록 — 현장 QR 스캔 → 최소 정보 입력 → 즉시 활성 작업자 등록 (공개)
Route::get('/join/w/{site}/qr', [App\Http\Controllers\SimpleWorkerRegistrationController::class, 'qr'])->name('worker-join.qr');
Route::get('/join/w/{site}', [App\Http\Controllers\SimpleWorkerRegistrationController::class, 'form'])->name('worker-join.form');
Route::post('/join/w/{site}', [App\Http\Controllers\SimpleWorkerRegistrationController::class, 'store'])->name('worker-join.store');

// 게이트 QR 출퇴근 — 현장 출입구 QR 스캔 → 이름으로 본인 확인 → 출근/퇴근 (공개, 앱 불필요)
Route::get('/gate/{site}/qr', [App\Http\Controllers\GateAttendanceController::class, 'qr'])->name('gate.qr');
Route::get('/gate/{site}', [App\Http\Controllers\GateAttendanceController::class, 'show'])->name('gate.show');
Route::post('/gate/{site}/search', [App\Http\Controllers\GateAttendanceController::class, 'search'])->name('gate.search');
Route::post('/gate/{site}/punch', [App\Http\Controllers\GateAttendanceController::class, 'punch'])->name('gate.punch');
// 기억된 휴대폰으로 본인 자동 인식 — 이름 검색을 건너뛴다.
Route::post('/gate/{site}/me', [App\Http\Controllers\GateAttendanceController::class, 'me'])->name('gate.me');
Route::post('/gate/{site}/remember', [App\Http\Controllers\GateAttendanceController::class, 'remember'])->name('gate.remember');
Route::post('/gate/{site}/forget', [App\Http\Controllers\GateAttendanceController::class, 'forget'])->name('gate.forget');

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

/**
 * 배포 확인 — 지금 이 서버가 어느 커밋을 돌리고 있나.
 *
 * 빌드(`npm run build`)가 public/build/version.json 에 커밋을 새겨 두고 여기서 읽는다.
 * 배포 서버에는 .git 이 없을 수 있고 shell_exec 가 막혀 있을 수도 있어, 런타임에
 * 알아내려는 시도는 조용히 빈 값이 된다.
 *
 * 로그인 없이 열리지만 커밋 해시와 기능 유무만 보여준다 — 배포가 됐는지 확인하는 데
 * 로그인을 요구하면, 정작 배포가 깨져 로그인이 안 될 때 쓸 수 없다.
 */
Route::get('/build-version', function () {
    $path = public_path('build/version.json');
    $version = is_readable($path)
        ? (json_decode((string) file_get_contents($path), true) ?: [])
        : [];

    $spa = resource_path('views/smart-company/index.blade.php');
    $spaSource = is_readable($spa) ? (string) file_get_contents($spa) : '';

    return response()->json([
        'commit' => $version['commit'] ?? null,
        'commit_short' => isset($version['commit']) ? substr((string) $version['commit'], 0, 7) : null,
        'branch' => $version['branch'] ?? null,
        'subject' => $version['subject'] ?? null,
        'committed_at' => $version['committed_at'] ?? null,
        'built_at' => $version['built_at'] ?? null,
        'checked_at' => now()->toIso8601String(),
        'env' => app()->environment(),
        // 배포가 실제로 반영됐는지는 해시보다 "이 기능이 있나" 로 확인하는 편이 빠르다.
        'has' => [
            'admin_shell' => is_readable(public_path('js/admin-shell.js')),
            'admin_screens' => str_contains($spaSource, 'nav-applicant-admin'),
            'ops_room' => str_contains($spaSource, 'data-view="opsroom"'),
            'old_company_name' => str_contains($spaSource, 'NASON'),
        ],
    ]);
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
