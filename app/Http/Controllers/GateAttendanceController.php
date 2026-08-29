<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Site;
use App\Models\WorkerDevice;
use App\Services\Attendance\GateAttendanceService;
use App\Support\QrPosters;
use App\Support\WorkerLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 게이트 QR 출퇴근 — 현장 출입구 QR(로그인 불필요). 이름으로 본인을 찾아 출근/퇴근을 찍는다.
 */
class GateAttendanceController extends Controller
{
    public function __construct(private readonly GateAttendanceService $service) {}

    /** 출입구 게이트 페이지(모바일). */
    public function show(Request $request, Site $site): View
    {
        return view('gate.index', [
            'site' => $site,
            // 기기가 기억돼 있으면 그 작업자의 언어로 열린다. 아니면 QR 의 ?lang= 또는 기본값.
            'lang' => WorkerLang::resolve($request->query('lang')),
            'langOptions' => WorkerLang::OPTIONS,
            'dict' => WorkerLang::gate(),
        ]);
    }

    /** 인쇄용 게이트 QR 포스터. */
    public function qr(Site $site): View
    {
        return view('gate.qr', [
            'site' => $site,
            'poster' => QrPosters::make($site, QrPosters::GATE),
        ]);
    }

    /** 전화번호 뒷 4자리로 본인 찾기 — 게이트의 기본 확인 방법. */
    public function identify(Request $request, Site $site): JsonResponse
    {
        $last4 = (string) $request->input('last4', '');

        return response()->json([
            'success' => true,
            'workers' => $this->service->identify($site, $last4)->all(),
        ]);
    }

    /**
     * 이름/소속 자동완성 — 번호가 등록되지 않은 사람을 위한 예비 통로.
     *
     * 두 글자 미만은 받지 않는다. 한 글자를 허용하면 알파벳을 돌려가며 현장 명단을
     * 통째로 훑을 수 있고, 그 명단은 이 화면을 열 수 있는 누구에게나 열려 있다.
     */
    public function search(Request $request, Site $site): JsonResponse
    {
        $q = trim((string) $request->query('q', $request->input('q', '')));

        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'workers' => [], 'tooShort' => true]);
        }

        return response()->json(['success' => true, 'workers' => $this->service->search($site, $q)->all()]);
    }

    /**
     * 기억된 기기로 본인 자동 인식 — 이름 검색을 건너뛴다.
     * 다른 현장에 배정된 작업자이거나 비활성이면 인식하지 않고 검색 화면으로 보낸다.
     */
    public function me(Request $request, Site $site): JsonResponse
    {
        $token = (string) $request->input('device_token', '');
        $employee = $token !== '' ? WorkerDevice::resolve($token) : null;

        if (! $employee || $employee->employment_status !== 'active' || (int) $employee->site_id !== (int) $site->id) {
            return response()->json(['success' => false, 'recognized' => false]);
        }

        return response()->json([
            'recognized' => true,
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'company' => $employee->company?->name ?: ($employee->badge_company_name ?: ''), 'role' => $employee->role ?: ''],
            'lang' => WorkerLang::resolve($employee->preferred_language),
        ] + $this->service->status($employee, $site));
    }

    /** 이 휴대폰을 내 것으로 기억 — 이름으로 찾아 찍은 뒤 다음부터 건너뛰고 싶을 때. */
    public function remember(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer']]);

        $employee = Employee::query()->where('id', $data['employee_id'])
            ->where('site_id', $site->id)->where('employment_status', 'active')->first();

        if (! $employee) {
            return response()->json(['success' => false, 'error' => '작업자를 찾을 수 없습니다.'], 404);
        }

        return response()->json([
            'success' => true,
            'device_token' => WorkerDevice::issueFor($employee, $request->userAgent()),
            'lang' => WorkerLang::resolve($employee->preferred_language),
        ]);
    }

    /** 기기 기억 해제 — 휴대폰을 바꿨거나 남의 폰으로 찍었을 때. */
    public function forget(Request $request, Site $site): JsonResponse
    {
        $token = (string) $request->input('device_token', '');
        if ($token !== '') {
            WorkerDevice::forget($token);
        }

        return response()->json(['success' => true]);
    }

    /** 출근/퇴근 기록(자동 판별). */
    public function punch(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
            // 어떤 방법으로 본인을 확인했는지 — 기록에 남겨 두면 나중에 "이 출근은
            // 어떻게 확인된 것인가" 를 되짚을 수 있다.
            'identified_by' => ['nullable', 'in:phone4,name,device'],
        ]);

        $employee = Employee::query()->where('id', $data['employee_id'])->where('site_id', $site->id)->first();
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '작업자를 찾을 수 없습니다.'], 404);
        }

        // 폰이 보낸 ip 는 믿지 않는다 — 서버가 본 주소로 넣는다(작업자 앱과 같은 규칙).
        // 현장 WiFi/망이 등록돼 있으면 이 값만으로도 현장 확인이 된다.
        $data['ip'] = $request->ip();

        return response()->json($this->service->punch($employee, $site, $data));
    }
}
