<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Site;
use App\Models\WorkerDevice;
use App\Services\Attendance\GateAttendanceService;
use App\Support\AppLocale;
use App\Support\QrPosters;
use App\Support\WorkerDeviceSession;
use App\Support\WorkerLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** 현장 QR 하나, 신원 규칙 하나: 기억된 휴대폰 아니면 전화번호 뒷 4자리. */
class GateAttendanceController extends Controller
{
    public function __construct(private readonly GateAttendanceService $service) {}

    public function show(Request $request, Site $site): View
    {
        abort_unless($site->status === 'active', 404);

        return view('gate.index', [
            'site' => $site,
            // 고른 언어는 이 화면 안에만 두지 않는다 — 쿠키에 남은 선택을 먼저 본다.
            // 그래야 여기서 스페인어를 고른 사람이 다음 화면에서 다시 한글을 만나지 않는다.
            'lang' => WorkerLang::resolve($request->query('lang', $request->cookie(AppLocale::COOKIE))),
            'langOptions' => WorkerLang::OPTIONS, 'dict' => WorkerLang::gate(),
        ]);
    }

    public function qr(Site $site): View
    {
        return view('gate.qr', ['site' => $site, 'poster' => QrPosters::make($site, QrPosters::GATE)]);
    }

    /**
     * 전화번호 뒷 4자리로 본인 찾기 — 게이트의 유일한 입구.
     *
     * <b>사장님 결정(2026-09-23):</b> «PIN 을 없애라. 등록된 사람은 아무것도 묻지 말고
     * 바로 되게 하라. 보안은 나중에 정한다.»
     *
     * 그 전에는 PIN 이 «가진 것(폰) + 아는 것(PIN)» 의 절반을 맡았다. 지금은 그 절반이
     * 없다 — 같은 현장의 누군가가 남의 뒷 4자리를 알면 대신 찍을 수 있다. 그 대가로
     * 얻는 것은, 현장에 온 사람이 아무것도 배우지 않고 4자리만 눌러 출근을 찍는 것이다.
     * 안 찍힌 출퇴근은 없는 근무가 되고 그건 그 사람 임금이다 — 그래서 이 선택이다.
     *
     * 되돌릴 때는 claim() 에 «아는 것» 을 한 겹 더하면 된다 — 문의 모양은 그대로 두고
     * 그 자리에 무엇을 요구할지만 바꾸는 자리다.
     */
    public function identify(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->status === 'active', 404);

        $last4 = preg_replace('/\D/', '', (string) $request->input('last4', '')) ?: '';

        return response()->json([
            'success' => true,
            'workers' => $this->service->identify($site, $last4)->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /** 뒷 4자리가 겹칠 때만 쓰는 예비 통로 — 이름으로 찾는다. */
    public function search(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->status === 'active', 404);

        $q = trim((string) $request->input('q', ''));

        return response()->json([
            'success' => true,
            'workers' => mb_strlen($q) < 2 ? [] : $this->service->search($site, $q)->all(),
            'tooShort' => mb_strlen($q) < 2,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * 「이 사람이 나다」 — 고른 사람으로 이 휴대폰을 연결하고 곧바로 출퇴근 화면을 연다.
     *
     * 다음부터는 이 단계도 없다. 휴대폰이 기억돼 QR 만 찍으면 출근 단추가 바로 뜬다.
     */
    public function claim(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->status === 'active', 404);

        $data = $request->validate(['employee_id' => ['required', 'integer']]);

        $employee = Employee::query()
            ->where('id', $data['employee_id'])
            ->where('site_id', $site->id)
            ->where('employment_status', 'active')
            ->first();

        if (! $employee) {
            return response()->json(['success' => false, 'error' => '이 현장에서 찾을 수 없습니다. 인사담당자에게 문의하세요. / Not found at this site — contact HR.'], 422);
        }

        $token = WorkerDevice::issueFor($employee, $request->userAgent(), verified: true);
        // 계정이 있으면 세션도 열어 둔다 — 작업자 앱(메시지·문서)이 같은 휴대폰에서 바로 열린다.
        WorkerDeviceSession::openFor($request, $token);

        return response()->json([
            'success' => true,
            'device_token' => $token,
            'csrf_token' => csrf_token(),
            'lang' => WorkerLang::resolve($employee->preferred_language),
        ])->header('Cache-Control', 'no-store');
    }

    /** 예전 화면의 «이 폰 기억하기» — 이제 고르는 순간 기억하므로 같은 일을 한다. */
    public function remember(Request $request, Site $site): JsonResponse
    {
        return $this->claim($request, $site);
    }

    private function identity(Request $request, Site $site): ?Employee
    {
        if ($site->status !== 'active') {
            return null;
        }
        $token = (string) $request->input('device_token', '');
        $employee = $token !== '' ? WorkerDevice::resolve($token, requireVerified: true) : null;
        if (! $employee || $employee->employment_status !== 'active'
            || (int) $employee->site_id !== (int) $site->id) {
            return null;
        }

        // 계정이 있는지는 따지지 않는다. 이 화면이 하는 일은 출퇴근 기록뿐이고,
        // 계정은 «앱에서 남의 글을 볼 수 있는가» 를 정하는 다른 문제다. 계정을 요구하면
        // 계정이 없는 옛 직원이 자기 출근을 못 찍는다 — 그건 곧 그 사람 임금이다.
        return $employee;
    }

    public function me(Request $request, Site $site): JsonResponse
    {
        $employee = $this->identity($request, $site);
        if (! $employee) {
            return response()->json(['success' => false, 'recognized' => false]);
        }

        return response()->json([
            'recognized' => true, 'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'lang' => WorkerLang::resolve($employee->preferred_language),
        ] + $this->service->status($employee, $site))->header('Cache-Control', 'no-store');
    }

    public function forget(Request $request, Site $site): JsonResponse
    {
        $token = (string) $request->input('device_token', '');
        $employee = WorkerDevice::resolve($token, requireVerified: true);
        WorkerDevice::forget($token);
        if ($employee && (int) $request->user()?->employee_id === (int) $employee->id) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['success' => true]);
    }

    public function punch(Request $request, Site $site): JsonResponse
    {
        $employee = $this->identity($request, $site);
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '전화번호 뒷 4자리로 본인을 먼저 찾아 주세요. / Find yourself with the last 4 digits of your phone.'], 401);
        }
        $data = $request->validate(['lat' => 'nullable|numeric', 'lng' => 'nullable|numeric', 'accuracy' => 'nullable|numeric']);
        $data['ip'] = $request->ip();
        $data['identified_by'] = 'device';

        // Serialize concurrent taps; the request's employee ID never selects the worker.
        return response()->json(DB::transaction(function () use ($employee, $site, $data) {
            $locked = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            return $this->service->punch($locked, $site, $data);
        }));
    }
}
