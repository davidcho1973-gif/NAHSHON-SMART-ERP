<?php

use App\Http\Controllers\AdminUploadController;
use App\Http\Controllers\AttendanceAppController;
use App\Http\Controllers\AttendanceGeoController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\DocumentIntelligenceController;
use App\Http\Controllers\EquipmentApiController;
use App\Http\Controllers\ExpenseAppController;
use App\Http\Controllers\ExpensePreApprovalController;
use App\Http\Controllers\GateAttendanceController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GuestViewController;
use App\Http\Controllers\HrAttendanceExportController;
use App\Http\Controllers\IntegratedDocumentController;
use App\Http\Controllers\MemberRegistrationController;
use App\Http\Controllers\MobileAskController;
use App\Http\Controllers\MobileDocumentController;
use App\Http\Controllers\MobileEquipmentController;
use App\Http\Controllers\MobileExpenseController;
use App\Http\Controllers\MobileOpsRoomController;
use App\Http\Controllers\OpsPhotoController;
use App\Http\Controllers\OpsVoiceController;
use App\Http\Controllers\OrgLogoController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PinAuthController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProjectContractDocumentController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QrPrintController;
use App\Http\Controllers\SimpleWorkerRegistrationController;
use App\Http\Controllers\SmartCompanyApiController;
use App\Http\Controllers\SmartCompanyController;
use App\Http\Controllers\VehicleApiController;
use App\Http\Controllers\W9FormController;
use App\Http\Controllers\WbsManualController;
use App\Http\Controllers\WbsPhotoController;
use App\Http\Controllers\WbsScheduleController;
use App\Http\Controllers\WebManifestController;
use App\Models\OrgSetting;
use App\Models\PushSubscription;
use App\Models\ReportRecipient;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Support\AccessPolicy;
use App\Support\MailReady;
use App\Support\Org;
use App\Support\UploadLimits;
use Aws\Ses\SesClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Resend\Laravel\ResendServiceProvider;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;

Route::get('/login', [GoogleAuthController::class, 'login'])->name('login');
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleAuthController::class, 'logout'])->name('logout')->middleware('auth');

// PIN 로그인 — 구글 계정이 없는 현장 인력이 자기 폰으로 들어오는 두 번째 문.
//
// 관문이 둘이다: 기억된 폰(가진 것) + 4자리 번호(아는 것). 폰이 등록되어 있지 않으면
// 번호 입력창 자체가 뜨지 않으므로, 링크 없이 번호만 대보는 공격은 성립하지 않는다.
// throttle 은 그 위에 한 겹 더 — 4자리는 만 가지뿐이라 속도를 묶어 두어야 한다.
Route::get('/auth/pin/setup/{token}', [PinAuthController::class, 'setupForm'])
    ->middleware('throttle:30,1')->name('pin.setup');
Route::post('/auth/pin/setup/{token}', [PinAuthController::class, 'setupStore'])
    ->middleware('throttle:10,1')->name('pin.setup.store');
Route::post('/auth/pin/who', [PinAuthController::class, 'who'])
    ->middleware('throttle:60,1')->name('pin.who');
Route::post('/auth/pin/login', [PinAuthController::class, 'login'])
    ->middleware('throttle:10,1')->name('pin.login');
Route::post('/auth/pin/forget', [PinAuthController::class, 'forget'])
    ->middleware('throttle:30,1')->name('pin.forget');

// 스크린샷 자동화용 서명 로그인 — erp:snap-links 가 발급한 10분짜리 서명 URL 로만 진입 가능.
// 구글 OAuth 뿐인 이 앱에서 헤드리스 브라우저가 화면을 찍을 수 있는 유일한 통로다. 감사 로그를 남긴다.
//
// 운영에는 열지 않는다 — 화면 캡처는 로컬 리허설 환경에서 하고, 로그인 우회 통로를
// 운영에 남겨 둘 이유가 없다(서명이 필요해도 표면은 없는 편이 낫다).
Route::get('/ops/snap-login', function (Request $request) {
    abort_unless(app()->environment('local'), 403);
    abort_unless($request->hasValidSignature(), 403);
    $user = User::query()->where('email', $request->query('email'))->firstOrFail();
    Auth::login($user);
    Log::info('ops.snap-login', ['email' => $user->email, 'view' => $request->query('view')]);
    $path = (string) $request->query('path', '');

    return redirect($path !== '' && str_starts_with($path, '/') && ! str_starts_with($path, '//')
        ? $path
        : '/?view='.((string) $request->query('view') ?: 'dashboard'));
})->name('ops.snap-login');

// 로컬 개발 전용 자동 로그인 — 헤드리스 스크린샷이 서명 링크 없이도 화면을 열 수 있게 한다.
// local 환경이 아니면 403. artisan serve 는 127.0.0.1 에만 바인딩되므로 외부 노출이 없다.
Route::get('/ops/snap-local/{view}', function (string $view) {
    abort_unless(app()->environment('local'), 403);
    $user = User::query()->where('access_role', 'super_admin')->orderBy('id')->firstOrFail();
    Auth::login($user);

    return $view === 'document-hub' ? redirect('/document-hub') : redirect('/?view='.$view);
})->where('view', '[a-z0-9-]+');

// 라우트 점검용 — 익명에게 열어 두면 공격 표면 지도를 그대로 넘겨주는 셈이라
// 로그인 + 시스템 관리자만 본다(바로 아래 /debug-logs-sec 와 같은 기준).
Route::get('/debug-routes-sec', function () {
    abort_unless(AccessPolicy::canManageSystem(auth()->user()), 403);

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
})->middleware('auth');
// 예전 관리자 패널(/admin)은 없어졌다 — 관리 화면은 전부 ERP 안으로 들어왔다.
// 북마크와 예전 링크가 404 를 만나지 않도록 홈으로 보낸다. 하위 경로(/admin/sites 등)도
// 함께 받는다.
Route::redirect('/admin', '/');
Route::get('/admin/{any}', fn () => redirect('/'))->where('any', '.*');

Route::middleware('auth')->group(function (): void {
    // 현장앱은 기존 현장(Site)을 직접 만들고 지운다 — 인증 없이 열어 두면
    // 방문자가 버튼 한 번으로 현장과 딸린 기록(협력사·QR·인원 마감)을 연쇄 삭제할 수 있다.
    Route::get('/field-app', function () {
        return view('field-app.index');
    })->name('field-app.index');
    Route::get('/field-app/{any}', function () {
        return view('field-app.index');
    })->where('any', '.*');
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
    Route::post('/wbs-api/upload-manual', [WbsManualController::class, 'upload'])->name('wbs-manual.upload');
    Route::get('/wbs-api/manuals', [WbsManualController::class, 'index'])->name('wbs-manual.index');
    Route::get('/wbs-api/manual/{manual}', [WbsManualController::class, 'show'])->name('wbs-manual.show');

    // 공정표 엑셀 교체 — 반드시 preview 로 무엇이 읽히고 무엇이 지워지는지 본 뒤에 replace.
    Route::post('/wbs-api/schedule/preview', [WbsScheduleController::class, 'preview'])->name('wbs-schedule.preview');
    Route::post('/wbs-api/schedule/replace', [WbsScheduleController::class, 'replace'])->name('wbs-schedule.replace');

    // 공정별 현장 사진 — 날짜별 업로드·열람. 원본 대신 축소본만 저장한다.
    Route::get('/wbs-api/photos', [WbsPhotoController::class, 'index'])->name('wbs-photos.index');
    Route::post('/wbs-api/photos', [WbsPhotoController::class, 'store'])->name('wbs-photos.store');
    Route::post('/wbs-api/photos/{photo}/caption', [WbsPhotoController::class, 'caption'])->name('wbs-photos.caption');
    Route::delete('/wbs-api/photos/{photo}', [WbsPhotoController::class, 'destroy'])->name('wbs-photos.destroy');
    Route::get('/wbs-api/photos/{photo}/file', [WbsPhotoController::class, 'file'])->name('wbs-photos.file');
    Route::get('/wbs-api/photos/{photo}/thumb', [WbsPhotoController::class, 'thumb'])->name('wbs-photos.thumb');

    // 조직 설정 — 로고 그림 올리기·지우기 (권한 확인은 컨트롤러가 한다)
    Route::post('/org-api/logo', [OrgLogoController::class, 'store'])->name('org.logo.store');
    Route::delete('/org-api/logo', [OrgLogoController::class, 'destroy'])->name('org.logo.destroy');

    // 문서통합관리 — 업로드(멀티파트) → AI 자동분석(백그라운드) → 상태 폴링 → 원본 열람
    Route::post('/docs-api/upload', [IntegratedDocumentController::class, 'upload'])->name('docs.upload');
    Route::get('/docs-api/status', [IntegratedDocumentController::class, 'status'])->name('docs.status');
    Route::get('/docs-api/file/{document}', [IntegratedDocumentController::class, 'show'])->name('docs.show');

    // 조달 관리 — 발주서/선적서 AI 분석(업로드 → 추출·단계 판정) + 근거 서류 열람
    Route::post('/procurement-api/analyze', [ProcurementController::class, 'analyze'])->name('procurement.analyze');
    Route::get('/procurement-api/file/{item}', [ProcurementController::class, 'showFile'])->name('procurement.file');

    // 하이브리드 자동 출퇴근 — 작업자 앱이 위치/WiFi 신호 전송 + 현재 상태 조회
    Route::post('/attendance-geo/ping', [AttendanceGeoController::class, 'ping'])->name('attendance-geo.ping');
    Route::get('/attendance-geo/status', [AttendanceGeoController::class, 'status'])->name('attendance-geo.status');

    // Vehicle API Routes
    Route::post('/vehicle-api/scan-rental', [VehicleApiController::class, 'scanRental'])->name('vehicle.scan-rental');
    Route::post('/vehicle-api/save', [VehicleApiController::class, 'saveVehicle'])->name('vehicle.save');
    Route::post('/vehicle-api/assign', [VehicleApiController::class, 'assignVehicle'])->name('vehicle.assign');
    Route::post('/vehicle-api/return', [VehicleApiController::class, 'returnVehicle'])->name('vehicle.return');
    Route::get('/vehicle-api/{vehicle}/history', [VehicleApiController::class, 'getRentalHistory'])->name('vehicle.history');
    Route::get('/vehicle-api/file', [VehicleApiController::class, 'serveFile'])->name('vehicle.file');

    // Equipment API Routes
    Route::post('/equipment-api/scan-rental', [EquipmentApiController::class, 'scanRental'])->name('equipment.scan-rental');
    Route::post('/equipment-api/save', [EquipmentApiController::class, 'saveEquipment'])->name('equipment.save');
    Route::post('/equipment-api/scan-inventory', [EquipmentApiController::class, 'scanInventory'])->name('equipment.scan-inventory');
    Route::post('/equipment-api/save-inventory', [EquipmentApiController::class, 'saveInventory'])->name('equipment.save-inventory');
    Route::post('/equipment-api/assign', [EquipmentApiController::class, 'assignEquipment'])->name('equipment.assign');
    Route::post('/equipment-api/return', [EquipmentApiController::class, 'returnEquipment'])->name('equipment.return');
    Route::get('/equipment-api/{equipment}/history', [EquipmentApiController::class, 'getRentalHistory'])->name('equipment.history');
    Route::get('/equipment-api/file', [EquipmentApiController::class, 'serveFile'])->name('equipment.file');
    Route::post('/equipment-api/{equipment}/update', [EquipmentApiController::class, 'updateEquipment'])->name('equipment.update');
    Route::post('/equipment-api/{equipment}/delete', [EquipmentApiController::class, 'deleteEquipment'])->name('equipment.delete');

    // Mobile Equipment Routes
    Route::get('/mobile-equipment/index', [MobileEquipmentController::class, 'index'])->name('mobile-equipment.index');
    Route::get('/mobile-equipment/wizard', [MobileEquipmentController::class, 'wizard'])->name('mobile-equipment.wizard');
    Route::post('/mobile-equipment/scan-photo', [MobileEquipmentController::class, 'scanPhoto'])->name('mobile-equipment.scan-photo');
    Route::post('/mobile-equipment/scan-photos-batch', [MobileEquipmentController::class, 'scanPhotosBatch'])->name('mobile-equipment.scan-photos-batch');
    Route::post('/mobile-equipment/store', [MobileEquipmentController::class, 'store'])->name('mobile-equipment.store');
    Route::post('/mobile-equipment/store-batch', [MobileEquipmentController::class, 'storeBatch'])->name('mobile-equipment.store-batch');
    Route::put('/mobile-equipment/{equipment}', [MobileEquipmentController::class, 'update'])->name('mobile-equipment.update');
    Route::delete('/mobile-equipment/{equipment}', [MobileEquipmentController::class, 'destroy'])->name('mobile-equipment.destroy');

    // Payroll documents (printable payslip + WH-347 certified payroll)
    Route::get('/payroll/run/{run}/certified', [PayrollController::class, 'certified'])->name('payroll.certified');
    Route::get('/payroll/payslip/{payslip}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

    // 관리자 화면의 파일 업로드(multipart) — SPA 의 JSON API 로는 파일을 실을 수 없다.
    Route::post('/admin-api/contracts/{contract}/documents', [AdminUploadController::class, 'contractDocument'])
        ->name('admin.contract-document.upload');
    Route::post('/admin-api/applicants/{applicant}/badge-photo', [AdminUploadController::class, 'applicantBadgePhoto'])
        ->name('admin.applicant-badge-photo.upload');

    // Private contract files — authenticated and access-scope checked before download.
    Route::get('/contracts/documents/{document}/download', [ProjectContractDocumentController::class, 'download'])
        ->name('project-contract-document.download');

    // AI construction document intelligence hub — private originals, search index and preventive actions.
    Route::get('/document-hub', [DocumentIntelligenceController::class, 'index'])->name('document-intelligence.index');
    Route::get('/document-hub/api/documents', [DocumentIntelligenceController::class, 'documents'])->name('document-intelligence.documents');
    Route::post('/document-hub/api/upload', [DocumentIntelligenceController::class, 'upload'])->name('document-intelligence.upload');
    Route::get('/document-hub/api/index.csv', [DocumentIntelligenceController::class, 'exportIndex'])->name('document-intelligence.export-index');
    // 현장이 비어 있는 문서 정리 — 목록·제안은 GET, 일괄 적용은 POST.
    Route::get('/document-hub/api/unassigned', [DocumentIntelligenceController::class, 'unassigned'])->name('document-intelligence.unassigned');
    Route::post('/document-hub/api/assign-site', [DocumentIntelligenceController::class, 'assignSite'])->name('document-intelligence.assign-site');
    Route::get('/document-hub/api/documents/{document}', [DocumentIntelligenceController::class, 'show'])->name('document-intelligence.show');
    Route::post('/document-hub/api/documents/{document}/reanalyze', [DocumentIntelligenceController::class, 'reanalyze'])->name('document-intelligence.reanalyze');
    Route::post('/document-hub/api/reanalyze-stuck', [DocumentIntelligenceController::class, 'reanalyzeStuck'])->name('document-intelligence.reanalyze-stuck');
    Route::post('/document-hub/api/reanalyze-untranslated', [DocumentIntelligenceController::class, 'reanalyzeUntranslated'])->name('document-intelligence.reanalyze-untranslated');
    // 도면 → 물량, 시방 → 제출물. 결과는 대기줄이 아니라 대장으로 바로 간다.
    Route::post('/document-hub/api/documents/{document}/takeoff', [DocumentIntelligenceController::class, 'takeoff'])->name('document-intelligence.takeoff');
    Route::post('/document-hub/api/documents/{document}/submittals', [DocumentIntelligenceController::class, 'extractSubmittals'])->name('document-intelligence.submittals');
    // 오래 걸리는 AI 작업의 진행 상태 — 화면이 번호표로 물어본다(504 를 막는 길).
    Route::get('/document-hub/api/ai-jobs/{job}', [DocumentIntelligenceController::class, 'aiJob'])->name('document-intelligence.ai-job');
    Route::patch('/document-hub/api/documents/{document}/review', [DocumentIntelligenceController::class, 'review'])->name('document-intelligence.review');
    Route::delete('/document-hub/api/documents/{document}', [DocumentIntelligenceController::class, 'destroy'])->name('document-intelligence.destroy');
    Route::patch('/document-hub/api/actions/{action}', [DocumentIntelligenceController::class, 'updateAction'])->name('document-intelligence.action.update');
    Route::get('/document-hub/documents/{document}/download', [DocumentIntelligenceController::class, 'download'])->name('document-intelligence.download');
    Route::get('/document-hub/documents/{document}/preview', [DocumentIntelligenceController::class, 'preview'])->name('document-intelligence.preview');

    // QR Attendance mobile app
    Route::get('/attendance-app', [AttendanceAppController::class, 'index'])->name('attendance-app.index');
    // 작업자 홈이 쓰는 두 개. 화면은 이 둘만 보고 자동 → 직접 → QR 로 내려간다.
    Route::get('/attendance-app/home', [AttendanceAppController::class, 'home'])->name('attendance-app.home');
    // 관리자가 자기 직원 정보를 스스로 만들어 잇는다 — 앱 관리를 겸하는 소장에게
    // «남에게 부탁하라» 고 말하는 화면은 틀렸다. 그 사람이 바로 그 «남» 이다.
    Route::post('/attendance-app/self-link', [AttendanceAppController::class, 'selfLink'])
        ->name('attendance-app.self-link');
    Route::post('/attendance-app/punch', [AttendanceAppController::class, 'punch'])->name('attendance-app.punch');
    Route::post('/attendance-app/correction', [AttendanceAppController::class, 'requestCorrection'])->name('attendance-app.correction');
    // 언어 선택을 서버에도 남긴다 — 쿠키만 두면 폰을 바꿀 때 다시 골라야 하고,
    // 서버가 그리는 화면(현장 기록·물어보기·문서)은 첫 화면과 다른 말을 하게 된다.
    Route::post('/attendance-app/language', [AttendanceAppController::class, 'language'])
        ->middleware('throttle:60,1')->name('attendance-app.language');

    // 영수증 앱 — 사진 한 장으로 경비 접수(ERP 등록과 같은 판독·원장·승인 회로).
    Route::get('/expense-app', [ExpenseAppController::class, 'index'])->name('expense-app.index');
    Route::post('/expense-app/submit', [ExpenseAppController::class, 'submit'])->name('expense-app.submit');
    Route::get('/expense-app/list', [ExpenseAppController::class, 'list'])->name('expense-app.list');
    // 상황실 사진 업로드 — 한 요청에 한 장씩(본문이 작아 크기 제한이 사실상 사라진다)
    Route::post('/ops-api/photo', [OpsPhotoController::class, 'store'])->name('ops.photo');
    // 말한 것을 글자로 — 장갑 낀 손으로 타자를 치지 않아도 되게. 녹음은 보관하지 않는다.
    Route::post('/ops-api/voice', [OpsVoiceController::class, 'store'])
        ->middleware('throttle:30,1')->name('ops.voice');

    // 모바일 현장 상황실 — 원문 기록 보기·올리기·수정·삭제
    Route::get('/attendance-app/ops-room', [MobileOpsRoomController::class, 'index'])->name('attendance-app.ops-room');
    // 폰으로 문서 올리기 — 현장에서 손에 들어온 도면·계약서를 그 자리에서 문서함으로.
    Route::get('/attendance-app/docs', [MobileDocumentController::class, 'index'])->name('attendance-app.docs');
    // 물어보기 — 도면·서류·대장에 대고 묻는다. 답은 물어본 사람만 본다.
    Route::get('/attendance-app/ask', [MobileAskController::class, 'index'])->name('attendance-app.ask');
    Route::post('/ask-api/question', [MobileAskController::class, 'question'])
        ->middleware('throttle:30,1')->name('ask.question');
    // 공종별 오늘 보고 — 반장이 자기 몫을 확정한다(현황판은 ERP 쪽 api_tradeReportBoard).
    Route::post('/ops-api/trade-report/submit', [MobileOpsRoomController::class, 'submitTradeReport'])
        ->name('ops.trade-report.submit');
    // 제출한 것이 ERP 로 넘어갔는지 — 반영은 응답 뒤에 돌기 때문에 화면이 잠깐 물어본다.
    Route::get('/ops-api/trade-report/status', [MobileOpsRoomController::class, 'tradeReportStatus'])
        ->name('ops.trade-report.status');
    Route::get('/attendance-app/messages', [CommunicationController::class, 'index'])->name('communication.index');
    Route::post('/attendance-app/messages/direct', [CommunicationController::class, 'startDirect'])->name('communication.direct.start');
    Route::post('/attendance-app/messages/notifications/read', [CommunicationController::class, 'readNotifications'])->name('communication.notifications.read');

    // 푸시 알림 — 이 기기로 받겠다는 등록/해지. 화면이 꺼져 있어도 지시가 닿는 길.
    Route::get('/push/key', [PushSubscriptionController::class, 'key'])->name('push.key');
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    // 채팅 화면에서 방 만들기·정리 — 규칙은 관리 서비스 한 곳에 있고 여기서는 부르기만 한다.
    Route::post('/attendance-app/messages/rooms', [CommunicationController::class, 'storeRoom'])->name('communication.room.store');
    Route::delete('/attendance-app/messages/rooms/{room}', [CommunicationController::class, 'destroyRoom'])->name('communication.room.destroy');
    Route::get('/attendance-app/messages/{room}/files/{file}', [CommunicationController::class, 'file'])->name('communication.file');
    // 새로고침 없이 대화가 흐르게 — 마지막으로 받은 번호 이후만.
    Route::get('/attendance-app/messages/{room}/stream', [CommunicationController::class, 'stream'])->name('communication.stream');
    // 이 방에 누가 있는지 · 잘못 쓴 글 고치기·지우기(본인)
    Route::get('/attendance-app/messages/{room}/members', [CommunicationController::class, 'members'])->name('communication.members');
    Route::patch('/attendance-app/messages/{room}/{message}', [CommunicationController::class, 'updateMessage'])->name('communication.message.update');
    Route::delete('/attendance-app/messages/{room}/{message}', [CommunicationController::class, 'destroyMessage'])->name('communication.message.destroy');
    Route::get('/attendance-app/messages/{room}', [CommunicationController::class, 'show'])->name('communication.show');
    Route::post('/attendance-app/messages/{room}', [CommunicationController::class, 'store'])->name('communication.store');
    Route::get('/attendance-app/team/{token}', [AttendanceAppController::class, 'team'])->name('attendance-app.team');
    Route::post('/attendance-app/team/{token}', [AttendanceAppController::class, 'recordTeam'])->name('attendance-app.team.record');
    Route::get('/attendance-app/team/{token}/crew', [AttendanceAppController::class, 'crew'])->name('attendance-app.crew');
    Route::post('/attendance-app/team/{token}/crew', [AttendanceAppController::class, 'recordCrew'])->name('attendance-app.crew.record');
    Route::post('/attendance-app/team/{token}/crew/daily-close', [AttendanceAppController::class, 'closeCrewDay'])->name('attendance-app.crew.daily-close');
    Route::get('/attendance-app/badge/{token}', [AttendanceAppController::class, 'badge'])->name('attendance-app.badge');
    Route::get('/attendance-app/employee/{employee}/badge-qr', [AttendanceAppController::class, 'employeeBadgeQr'])->name('attendance-app.employee.badge-qr');
    // 직영 작업자에게 건네는 앱 설치 카드(인쇄용). 협력사는 게이트 포스터 한 장이면 되지만
    // 직영은 사람마다 로그인 계정이 달라서 종이도 사람마다 나온다.
    Route::get('/attendance-app/employee/{employee}/install-card', [AttendanceAppController::class, 'installCard'])->name('attendance-app.employee.install-card');
    // 작업자에게 링크를 "보내는" 화면 — 복사·QR·문자 문구. 인쇄 카드와 목적이 다르다.
    Route::get('/attendance-app/employee/{employee}/share', [AttendanceAppController::class, 'shareLink'])->name('attendance-app.employee.share');

    // 손님 링크 QR 인쇄 카드 — 발급한 사람이 손님에게 건네는 종이/화면.
    Route::get('/guest-link/{link}/qr', [GuestViewController::class, 'qr'])->name('guest-link.qr');

    // W-9 인쇄 — 직원 관리에서 바로 뽑는다. 제출 전이면 아는 칸이 채워진 종이가 나오고,
    // 제출 후면 보관용 사본(1099 신고의 근거 서류)이 나온다.
    // 빈 양식 — 현장에 챙겨 가는 종이. {employee} 보다 먼저 와야 'blank' 가 직원 ID 로 읽히지 않는다.
    Route::get('/w9/blank/print', [W9FormController::class, 'blank'])->name('w9.blank');
    Route::get('/w9/{employee}/print', [W9FormController::class, 'printable'])->name('w9.print');

    // HR daily attendance status report — styled Excel (.xlsx) export
    Route::get('/hr/attendance/export', HrAttendanceExportController::class.'@export')
        ->name('hr.attendance.export');

    // Team QR Code Printable Sheet
    Route::get('/team/{team}/qr', [SmartCompanyController::class, 'teamQr'])->name('team.qr');

    // Universal Scanner and Compatibility Adapter Route
    Route::post('/smart-company-api/{method}', SmartCompanyApiController::class)
        ->where('method', '[A-Za-z0-9_]+')
        ->name('api.smart-company');
});

// 현장 QR 모아 인쇄 — 게이트·간편등록(직접/협력사)·입사지원서 포스터를 한 번에 출력
Route::get('/print/qr/{site}', [QrPrintController::class, 'sheet'])
    ->middleware(['auth'])
    ->name('qr-print.sheet');

// 손님 전용 현황 — 회수 가능한 토큰 하나가 열쇠다(공개). 그 현장의 공정 현황만
// 보이고, 돈·사람 데이터는 화면을 만드는 단계에서 아예 뽑지 않는다.
// throttle: 토큰은 40자 난수라 추측이 사실상 불가능하지만, 시도 자체를 느리게 해 둔다.
Route::get('/guest/{token}', [GuestViewController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('guest.view');

// 간편 작업자 등록 — 현장 QR 스캔 → 최소 정보 입력 → 즉시 활성 작업자 등록 (공개)
//
// 로그인 없이 열리는 폼이라 자동화로 인원·회사 행을 대량 생성할 수 있었다. 현장에서
// 한 사람이 1분에 스무 번 등록할 일은 없으므로 그 선에서 묶는다(등록이 사라지는 게
// 아니라 잠시 뒤 다시 되는 정도). 이 폼은 계정을 만들지 않는다 — MemberRegistration
// 이 공개 출처를 알아보고 계정 발급을 관리자 승인 뒤로 미룬다.
Route::get('/join/w/{site}/qr', [SimpleWorkerRegistrationController::class, 'qr'])
    ->middleware('throttle:60,1')->name('worker-join.qr');
Route::get('/join/w/{site}', [SimpleWorkerRegistrationController::class, 'form'])
    ->middleware('throttle:60,1')->name('worker-join.form');
Route::post('/join/w/{site}', [SimpleWorkerRegistrationController::class, 'store'])
    ->middleware('throttle:20,1')->name('worker-join.store');

// 관리자 등록 — 현장소장·공정별 팀장·기사·안전관리자. 작업자와 문을 나눈다:
// 이메일과 직책이 필수이고(로그인과 결재선이 거기서 나온다), 공종은 관리자에게도 있다.
// 이 문으로 들어와도 ERP 권한은 생기지 않는다 — QR 은 복사·촬영되므로 계정은 승인 뒤에.
Route::get('/join/m/{site}/qr', [SimpleWorkerRegistrationController::class, 'managerQr'])
    ->middleware('throttle:60,1')->name('manager-join.qr');
Route::get('/join/m/{site}', [SimpleWorkerRegistrationController::class, 'managerForm'])
    ->middleware('throttle:60,1')->name('manager-join.form');
Route::post('/join/m/{site}', [SimpleWorkerRegistrationController::class, 'managerStore'])
    ->middleware('throttle:20,1')->name('manager-join.store');

// W-9 작성 — 간편 등록 완료 화면에서 서명된 링크로 진입(공개, 서명 URL 이 본인 확인을 대신).
// 1099 지급의 전제조건이라 등록 흐름에 바로 이어 붙였다. TIN 은 암호화 저장.
Route::get('/w9/{employee}', [W9FormController::class, 'show'])->middleware('signed')->name('w9.show');
Route::post('/w9/{employee}', [W9FormController::class, 'store'])->middleware('signed')->name('w9.store');

// 홈 화면에 추가할 때 브라우저가 읽는 파일. 로그인 뒤에 두면 브라우저가 못 읽어
// 설치가 조용히 실패한다 — 안에는 아이콘 주소와 화면 이름뿐이라 감출 것이 없다.
Route::get('/gate/{site}/manifest.webmanifest', [WebManifestController::class, 'gate'])->name('gate.manifest');
Route::get('/worker-app.webmanifest', [WebManifestController::class, 'worker'])->name('worker-app.manifest');
Route::get('/expense-app.webmanifest', [WebManifestController::class, 'expense'])->name('expense-app.manifest');
Route::get('/erp.webmanifest', [WebManifestController::class, 'erp'])->name('erp.manifest');

// 고객사 로고 그림. 로그인 화면과 게이트 화면이 쓰기 때문에 로그인 없이 열린다 —
// 회사가 명함과 간판에 이미 붙여 둔 그림이라 감출 것이 없다.
Route::get('/org/logo', [OrgLogoController::class, 'show'])->name('org.logo');

// 게이트 QR 출퇴근 — 현장 출입구 QR 스캔 → 전화번호 뒷 4자리로 본인 확인 → 출근/퇴근
// (공개, 앱 불필요)
//
// throttle: 이 경로는 로그인이 없어 직원 번호를 훑거나 남의 출퇴근을 대신 찍는 시도가
// 가능하다. 근태는 임금 기록이므로 속도를 묶어 자동화를 무디게 한다. 한도는 아침 러시를
// 기준으로 넉넉히 잡았다 — 현장 와이파이처럼 여러 사람이 같은 IP 를 쓰면 합산되므로,
// 조이다가 출근 줄을 세우는 쪽이 더 큰 사고다.
Route::get('/gate/{site}/qr', [GateAttendanceController::class, 'qr'])->name('gate.qr');
Route::get('/gate/{site}', [GateAttendanceController::class, 'show'])->name('gate.show');
// 뒷 4자리는 만 가지뿐이라, 이름 검색보다 조인다 — 한 사람이 아침에 한두 번 쓰는 길이다.
Route::post('/gate/{site}/identify', [GateAttendanceController::class, 'identify'])
    ->middleware('throttle:60,1')->name('gate.identify');
Route::post('/gate/{site}/search', [GateAttendanceController::class, 'search'])
    ->middleware('throttle:240,1')->name('gate.search');
Route::post('/gate/{site}/punch', [GateAttendanceController::class, 'punch'])
    ->middleware('throttle:240,1')->name('gate.punch');
// 기억된 휴대폰으로 본인 자동 인식 — 이름 검색을 건너뛴다.
Route::post('/gate/{site}/me', [GateAttendanceController::class, 'me'])
    ->middleware('throttle:240,1')->name('gate.me');
Route::post('/gate/{site}/remember', [GateAttendanceController::class, 'remember'])
    ->middleware('throttle:60,1')->name('gate.remember');
Route::post('/gate/{site}/forget', [GateAttendanceController::class, 'forget'])->name('gate.forget');

Route::get('/member/register/{token}/qr', [MemberRegistrationController::class, 'qr'])->name('member-registration.qr');
Route::get('/member/register/{token}', [MemberRegistrationController::class, 'show'])->name('member-registration.show');
Route::post('/member/register/{token}', [MemberRegistrationController::class, 'store'])->name('member-registration.store');
Route::get('/member/site/{site}/apply/qr', [MemberRegistrationController::class, 'siteQr'])->name('member-registration.site.qr');
Route::get('/member/site/{site}/apply', [MemberRegistrationController::class, 'siteShow'])->name('member-registration.site.show');
Route::post('/member/site/{site}/apply', [MemberRegistrationController::class, 'siteStore'])->name('member-registration.site.store');

/**
 * 서버 오류 로그 — 500 이 났을 때 원인을 보는 유일한 창.
 *
 * 예전에는 로그인 없이 열렸다. 주소가 길어서 안 들킬 뿐, 이 파일에는 오류와 함께
 * 이메일·요청 내용이 섞여 나온다. 판매용으로 남의 회사 데이터를 담을 제품에서
 * "주소를 모르면 못 본다" 는 잠금장치가 아니다.
 *
 * 기본은 마지막 300줄만 준다. 휴대폰에서 열어 마지막 오류를 확인하는 것이 이 화면의 용도다.
 */
Route::get('/debug-logs-sec-53298bfd9a', function (Request $request) {
    abort_unless(in_array($request->user()?->access_role, ['super_admin', 'admin'], true), 403);

    $path = storage_path('logs/laravel.log');

    if (! is_readable($path)) {
        return response('로그 파일이 없습니다. 아직 오류가 기록되지 않았습니다.', 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    $lines = max(50, min(2000, (int) $request->query('lines', 300)));
    $all = file($path, FILE_IGNORE_NEW_LINES) ?: [];

    return response(implode("\n", array_slice($all, -$lines)), 200)
        ->header('Content-Type', 'text/plain; charset=utf-8');
})->middleware('auth')->name('debug.logs');

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
Route::get('/build-version', function (Request $request) {
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
        // 이 배포가 누구의 것인가. 코드 하나로 고객마다 배포하다 보면, 배포를 열어
        // 놓고도 "이게 어느 고객 것이더라" 를 모르는 순간이 온다. 더 나쁜 경우는
        // 새 고객 배포가 기본값(우리 회사 이름) 그대로 서 있는 것이다 — 화면은
        // 멀쩡하고, 고객 눈에만 남의 회사 이름이 보인다.
        'org' => [
            'name' => Org::name(),
            'code' => Org::code(),
            'configured' => (bool) config('org.configured'),
            'customized_keys' => OrgSetting::query()->pluck('key')->all(),
        ],
        // 도메인이 절반만 바뀌는 사고가 흔하다 — 새 주소로 열리는데 APP_URL 은 옛 주소면,
        // QR·설치 카드·매니페스트가 전부 옛 주소를 가리킨다. 화면은 멀쩡해 보인다.
        'domain' => [
            'app_url' => config('app.url'),
            'served_from' => $request->getSchemeAndHttpHost(),
            'matches' => rtrim((string) config('app.url'), '/') === $request->getSchemeAndHttpHost(),
            // 구글 로그인은 이 값과 구글 콘솔이 <b>둘 다</b> 맞아야 된다. 한쪽만 바꾸면
            // 로그인이 통째로 막힌다.
            'google_redirect' => config('services.google.redirect'),
        ],
        // 캐시를 어디에 두고 있는가. 이름만 보면 사소해 보이지만 요금이 걸려 있다 —
        // database 로 두면 schedule:run 이 매분 캐시 표를 조회해 서버리스 데이터베이스가
        // 잠들 틈이 없다(1분마다 깨우면 24시간 깨어 있는 것과 같다). file 이어야 한다.
        // 설정이 되돌아가도 화면은 멀쩡하고 청구서에서만 드러나므로 여기에 적어 둔다.
        'cache' => [
            'store' => config('cache.default'),
            'wakes_database_every_minute' => config('cache.default') === 'database',
        ],
        // 스케줄러가 돌고 있는가. 이게 꺼져 있으면 자동 퇴근·문서 재분석·경비 계상이
        // 전부 조용히 멈춘다 — 화면은 멀쩡해 보여서 며칠 뒤에야 알아챈다.
        'scheduler' => (function (): array {
            $health = SystemHeartbeat::health(SystemHeartbeat::SCHEDULER);

            return $health + [
                'message' => match (true) {
                    $health['running'] => '스케줄러가 정상 동작 중입니다.',
                    $health['minutes_ago'] === null => '스케줄러가 한 번도 돈 적이 없습니다. Laravel Cloud 에서 스케줄러를 켜 주세요.',
                    default => "스케줄러가 {$health['minutes_ago']}분째 멈춰 있습니다. 자동 퇴근과 문서 분석이 진행되지 않습니다.",
                },
            ];
        })(),
        // 배포된 코드가 기대하는 표가 실제로 있는가.
        //
        // 코드는 새 컬럼을 쓰는데 마이그레이션이 안 돌면, 그 화면만 500 으로 죽는다 —
        // 배포는 초록불이고 다른 화면은 멀쩡해서 아무도 원인을 못 찾는다. 실제로
        // 간편등록이 이렇게 하루를 잃었다(직책 컬럼 하나). 여기에 숫자로 세워 둔다.
        'migrations' => (function (): array {
            try {
                $migrator = app('migrator');
                $ran = $migrator->getRepository()->getRan();
                $files = $migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]);
                $pending = array_values(array_diff(array_keys($files), $ran));

                return [
                    'pending' => count($pending),
                    'ok' => $pending === [],
                    'names' => array_slice($pending, 0, 5),
                    'message' => $pending === []
                        ? '모든 마이그레이션이 적용되어 있습니다.'
                        : count($pending).'개 마이그레이션이 아직 안 돌았습니다 — php artisan migrate --force 를 실행하세요. 그 표를 쓰는 화면은 지금 500 으로 죽습니다.',
                ];
            } catch (Throwable $e) {
                return ['pending' => null, 'ok' => null, 'message' => '확인 실패: '.$e->getMessage()];
            }
        })(),
        // 업로드 파일이 배포를 견디는 저장소에 있는가. local/public 이면 Laravel Cloud
        // 배포마다 문서 원본·현장 사진이 조용히 사라진다 — DB 기록은 남아서 화면에는
        // 멀쩡히 보이다가 열 때만 "파일 없음" 이 난다. 버킷을 붙이고도 환경변수
        // (DOCUMENT_STORAGE_DISK 등)를 안 넣어 로컬로 가는 사고가 실제로 있었다.
        'storage' => (function (): array {
            $docs = (string) config('document-intelligence.disk');
            $filed = (string) config('filesystems.documents_disk');
            $photos = (string) config('filesystems.wbs_photos_disk');
            $volatile = fn (string $d): bool => in_array($d, ['local', 'public'], true);

            return [
                'document_hub' => $docs,
                'document_management' => $filed,
                'wbs_photos' => $photos,
                'durable' => ! $volatile($docs) && ! $volatile($filed) && ! $volatile($photos),
            ];
        })(),
        // 파일을 실제로 몇 MB 까지 받는가.
        //
        // 한도는 코드가 아니라 PHP 설정에 있고, 그 설정은 public/.user.ini 에 적혀 있다.
        // 그런데 «적어 두었다» 와 «적용됐다» 는 다르다 — PHP-FPM 이 .user.ini 를 읽지
        // 않는 환경이면 기본값(2M/8M)이 그대로 살아 있고, 화면은 「최대 50MB」라고
        // 적어 둔 채 5MB 짜리 도면도 못 받는다. 어느 쪽인지 서버에 물어보지 않고는
        // 알 수 없는데, 물어볼 창구가 없어서 지금까지 추측으로 때웠다.
        //
        // effective 가 곧 사람이 화면에서 보는 숫자다. user_ini_applied 가 false 면
        // 파일이 안 올라가는 이유는 코드가 아니라 배포 환경이다.
        'uploads' => (function (): array {
            $post = UploadLimits::postMaxBytes();
            $file = UploadLimits::uploadMaxBytes();
            $mb = fn (int $bytes): float => round($bytes / 1048576, 1);

            return [
                'post_max_size_mb' => $mb($post),
                'upload_max_filesize_mb' => $mb($file),
                // 화면이 «파일당 최대» 로 적는 숫자 — 설정·PHP 파일 한도·본문 한도 중 최솟값.
                'effective_per_file_mb' => $mb(DocumentIntelligenceController::maxUploadBytes()),
                // .user.ini 는 64M/72M 을 적어 두었다. 그보다 작으면 안 읽힌 것이다.
                'user_ini_applied' => $post >= 72 * 1048576 && $file >= 64 * 1048576,
                'message' => $post >= 72 * 1048576 && $file >= 64 * 1048576
                    ? 'public/.user.ini 의 업로드 한도가 적용되어 있습니다.'
                    : 'public/.user.ini 가 적용되지 않았습니다 — 큰 파일은 서버가 받기 전에 잘립니다. 배포 환경의 PHP 설정을 확인하세요.',
            ];
        })(),
        // 메일이 진짜로 나가는 상태인가. AI 키와 똑같은 함정이 있다 — 넣었다고 믿었는데
        // 실은 안 들어간 경우가 화면에서 전혀 안 드러난다. 더 나쁜 것은 라라벨의 기본
        // 메일러가 `log` 라서, 설정이 없어도 발송이 <b>예외 없이 성공</b>한다는 점이다.
        // 로그 파일에만 쌓이고 원청은 영원히 못 받는데 화면에는 "발송했습니다" 가 뜬다.
        //
        // 값은 절대 내보내지 않는다 — 이 주소는 로그인 없이 열리므로 비밀번호는 물론이고
        // 발신 주소도 통째로는 안 적고 도메인만 적는다.
        'mail' => (function (): array {
            $mailer = (string) config('mail.default', 'log');
            $from = trim((string) config('mail.from.address', ''));
            $host = trim((string) config('mail.mailers.smtp.host', ''));
            $ready = MailReady::ok();

            // 설정이 됐어도 받을 사람이 없으면 아무 데도 안 간다. 이 둘은 다른 문제라
            // 따로 세어 둔다 — 안 그러면 "메일 설정은 초록불인데 왜 안 오지" 가 된다.
            $recipients = (function (): ?int {
                try {
                    return Schema::hasTable('report_recipients')
                        ? ReportRecipient::where('active', true)->count()
                        : null;
                } catch (Throwable) {
                    return null;
                }
            })();

            // 스킴이 틀리면 <b>다른 칸이 전부 초록이어도 한 통도 안 나간다.</b>
            // Symfony 가 받는 값은 smtp / smtps 둘뿐인데, MAIL_SCHEME=tls 라고 적는 것이
            // 흔한 오해다(우리 MAIL_SETUP.md 도 2026-08-30 까지 그렇게 적고 있었다).
            // 그 상태에서 MailReady 는 true 를 내고 발송 순간에 UnsupportedSchemeException
            // 으로 죽는다 — 점검이 초록불인데 메일이 안 가는 가장 나쁜 조합이라 따로 본다.
            $scheme = strtolower(trim((string) config('mail.mailers.smtp.scheme', '')));
            $schemeOk = $scheme === '' || in_array($scheme, ['smtp', 'smtps'], true);

            // 드라이버 패키지가 실제로 깔려 있는가. mailer 이름만 바꾸고 패키지를 안 넣으면
            // "Class not found" 로 죽는데, 설정 값만 보면 멀쩡해 보인다.
            $driverOk = match ($mailer) {
                'resend' => class_exists(ResendServiceProvider::class),
                'postmark' => class_exists(PostmarkApiTransport::class),
                'mailgun' => class_exists(MailgunApiTransport::class),
                'ses', 'ses-v2' => class_exists(SesClient::class),
                default => true,
            };

            return [
                'ready' => $ready && $schemeOk && $driverOk,
                'mailer' => $mailer,
                // 설정을 바꾸고 재배포를 안 하면 서버는 계속 옛 값으로 돈다.
                'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
                'scheme' => $scheme === '' ? '(비어 있음 — 정상)' : $scheme,
                'scheme_ok' => $schemeOk,
                'driver_installed' => $driverOk,
                'host_set' => $host !== '' && ! in_array(strtolower($host), ['127.0.0.1', 'localhost'], true),
                'port' => (int) config('mail.mailers.smtp.port', 0),
                'username_set' => trim((string) config('mail.mailers.smtp.username', '')) !== '',
                'password_set' => trim((string) config('mail.mailers.smtp.password', '')) !== '',
                // 발신 주소는 도메인만. 이 도메인이 SPF/DKIM 인증된 도메인과 달라야 하는
                // 경우는 없으므로, 여기만 봐도 대부분의 반송 원인을 짚을 수 있다.
                'from_domain' => str_contains($from, '@') ? substr($from, strpos($from, '@') + 1) : null,
                'from_is_placeholder' => $from === '' || str_ends_with(strtolower($from), '@example.com'),
                'daily_report_recipients' => $recipients,
                'message' => match (true) {
                    // 스킴 오류를 가장 먼저 짚는다 — 다른 칸이 다 채워져 있어서 사람이 절대 못 찾는다.
                    ! $schemeOk => "MAIL_SCHEME 값 «{$scheme}» 은 쓸 수 없습니다. 이 상태로는 다른 설정이 다 맞아도 한 통도 못 나갑니다 — 그 값을 지우세요(587 포트면 비워 두는 것이 정답, 465 포트면 smtps).",
                    ! $driverOk => "메일러 «{$mailer}» 의 드라이버 패키지가 설치돼 있지 않습니다. SMTP 방식으로 바꾸면 패키지 없이 됩니다.",
                    $ready && ($recipients ?? 0) > 0 => '설정은 정상입니다. 실제로 나가는지는 [조직 설정 > 메일 진단]에서 테스트 발송으로 확인하세요.',
                    $ready && $recipients === 0 => '메일은 나갈 수 있지만 일일 보고 수신처가 0명입니다 — [일일 보고 > 수신처 관리] 에서 받는 사람을 등록하세요.',
                    $ready => '설정은 정상입니다. 실제로 나가는지는 [조직 설정 > 메일 진단]에서 확인하세요.',
                    default => '메일이 아직 나가지 않습니다 — '.MailReady::why()
                        .' 지금은 [발송] 이 메일앱을 여는 것으로 대체되고, 정해진 시각 자동 발송은 아무것도 하지 않습니다.',
                },
            ];
        })(),

        // 어떤 AI 가 살아 있는가. 키를 넣었다고 믿었는데 실은 안 들어간 경우가 화면에서는
        // 전혀 드러나지 않는다 — 분석이 조용히 실패하거나(문서함), 기능이 조용히 사라진다
        // (도면 판독·교차검증). 값은 절대 내보내지 않고 "켜졌는가" 만 적는다.
        'ai' => (function (): array {
            $gemini = trim((string) config('services.gemini.api_key')) !== '';
            $anthropic = trim((string) config('services.anthropic.api_key')) !== '';
            $openai = trim((string) config('services.openai.api_key')) !== '';
            $crossCheckOn = (bool) config('document-intelligence.cross_check.enabled', true);

            // 최근 30일 AI 사용액 — 엔진이 셋이 되면 어디서 돈이 새는지 봐야 한다.
            $spend = (function (): array {
                try {
                    if (! Schema::hasTable('ai_usage_logs')) {
                        return ['ready' => false];
                    }
                    $rows = DB::table('ai_usage_logs')
                        ->where('occurred_at', '>=', now()->subDays(30))
                        ->selectRaw('engine, count(*) as calls, sum(cost_usd) as usd')
                        ->groupBy('engine')->get();

                    return [
                        'ready' => true,
                        'days' => 30,
                        'total_usd' => round((float) $rows->sum('usd'), 4),
                        'by_engine' => $rows->mapWithKeys(fn ($r) => [
                            $r->engine => ['calls' => (int) $r->calls, 'usd' => round((float) $r->usd, 4)],
                        ])->all(),
                    ];
                } catch (Throwable) {
                    return ['ready' => false];
                }
            })();

            return [
                'gemini' => $gemini,
                'anthropic' => $anthropic,
                'openai' => $openai,
                // 판독 엔진이 몇 개나 살아 있는가 — 셋이어야 3자 대조가 가능하다.
                'engines_live' => (int) $gemini + (int) $anthropic + (int) $openai,
                'ocr_engine' => strtolower(trim((string) config('services.ai_ocr.engine', 'gemini'))) ?: 'gemini',
                'spend_30d' => $spend,
                // 문서 분석의 1차 판독. 이게 false 면 문서함이 아무것도 못 읽는다.
                'document_analysis' => $gemini,
                // 도면 판독은 Claude 전용 — 키가 없으면 규칙 기반으로 후퇴한다.
                'drawing_vision' => $anthropic,
                // 두 번째 눈. enabled 여도 키가 없으면 살아 있지 않다(live=false).
                'cross_check' => [
                    'enabled' => $crossCheckOn,
                    'live' => $crossCheckOn && $anthropic,
                    'min_amount' => (float) config('document-intelligence.cross_check.min_amount', 1000),
                ],
                // 대화방의 AI 도우미(@AI). 꺼져 있으면 참여자 목록에도, 입력창에도
                // 나타나지 않는다 — 불러도 답이 없는 이름을 만들지 않기 위해서다.
                'chat_assistant' => $anthropic,
                // 영수증 앱(/expense-app). 이 값이 안 보이는 배포는 그 커밋 이전이다 —
                // "새 화면이 404" 가 코드 문제인지 배포 지연인지 여기서 갈린다.
                'expense_app' => true,
            ];
        })(),
        // 알림이 실제로 나갈 수 있는가. 열쇠(VAPID)가 없으면 화면은 알림 버튼을 감추고,
        // 아무도 "알림이 왜 안 오지" 를 묻지 않는다 — 조용히 없는 기능이 된다.
        'push' => (function (): array {
            $ready = trim((string) config('services.webpush.public_key')) !== ''
                && trim((string) config('services.webpush.private_key')) !== '';

            return [
                'configured' => $ready,
                'devices' => $ready && Schema::hasTable('push_subscriptions')
                    ? PushSubscription::query()->count()
                    : 0,
            ];
        })(),
        // 배포가 실제로 반영됐는지는 해시보다 "이 기능이 있나" 로 확인하는 편이 빠르다.
        'has' => [
            'admin_shell' => is_readable(public_path('js/admin-shell.js')),
            'admin_screens' => str_contains($spaSource, 'nav-applicant-admin'),
            'spa_only_admin' => ! str_contains($spaSource, "url('/admin')"),
            'ops_room' => str_contains($spaSource, 'data-view="opsroom"'),
            'old_company_name' => str_contains($spaSource, 'NASON'),
        ],
    ]);
});
