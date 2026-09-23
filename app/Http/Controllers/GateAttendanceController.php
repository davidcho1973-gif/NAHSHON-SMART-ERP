<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Attendance\GateAttendanceService;
use App\Services\Auth\PinAuthService;
use App\Support\QrPosters;
use App\Support\WorkerDeviceSession;
use App\Support\WorkerLang;
use App\Support\WorkerPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** One site QR, one identity rule: verified personal phone or phone + PIN. */
class GateAttendanceController extends Controller
{
    public function __construct(private readonly GateAttendanceService $service) {}

    public function show(Request $request, Site $site): View
    {
        abort_unless($site->status === 'active', 404);

        return view('gate.index', [
            'site' => $site, 'lang' => WorkerLang::resolve($request->query('lang')),
            'langOptions' => WorkerLang::OPTIONS, 'dict' => WorkerLang::gate(),
        ]);
    }

    public function qr(Site $site): View
    {
        return view('gate.qr', ['site' => $site, 'poster' => QrPosters::make($site, QrPosters::GATE)]);
    }

    // Public roster selection was not identity verification. Retire old open-page requests.
    public function identify(Request $request, Site $site): JsonResponse
    {
        return response()->json(['success' => false, 'error' => '화면을 새로고침하고 전화번호와 PIN으로 연결하세요. / Refresh and sign in with phone and PIN.'], 410);
    }

    public function search(Request $request, Site $site): JsonResponse
    {
        return $this->identify($request, $site);
    }

    public function remember(Request $request, Site $site): JsonResponse
    {
        return $this->identify($request, $site);
    }

    public function login(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->status === 'active', 404);
        $data = $request->validate(['phone' => 'required|string|max:40', 'pin' => 'required|digits:4']);
        $result = DB::transaction(function () use ($request, $site, $data): array {
            $candidates = WorkerPhone::employees($data['phone'])->limit(2)->get();
            $employee = $candidates->count() === 1 ? $candidates->first() : null;
            $user = $employee ? User::where('employee_id', $employee->id)->lockForUpdate()->first() : null;
            if (! $employee || $employee->employment_status !== 'active'
                || (int) $employee->site_id !== (int) $site->id
                || ! WorkerDeviceSession::mayEnterWithDeviceAlone($user)) {
                return ['success' => false];
            }
            $verified = app(PinAuthService::class)->verifyFor($user, $data['pin'], $request);
            if (! $verified['success']) {
                return ['success' => false];
            }
            $request->session()->regenerate();

            return ['success' => true, 'device_token' => WorkerDevice::issueFor($employee, $request->userAgent(), verified: true)];
        });
        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => '전화번호·PIN을 확인하세요. 계속 안 되거나 PIN을 잊었으면 인사담당자에게 초기화를 요청하세요. / Check phone and PIN, or ask HR to reset.'], 422);
        }

        return response()->json($result + ['csrf_token' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    private function identity(Request $request, Site $site): ?Employee
    {
        if ($site->status !== 'active') {
            return null;
        }
        $token = (string) $request->input('device_token', '');
        $employee = $token !== '' ? WorkerDevice::resolve($token, requireVerified: true) : null;
        if (! $employee || $employee->employment_status !== 'active'
            || (int) $employee->site_id !== (int) $site->id
            || ! WorkerDeviceSession::mayEnterWithDeviceAlone($employee->user)) {
            return null;
        }

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
            return response()->json(['success' => false, 'error' => '전화번호와 PIN으로 먼저 연결하세요. / Sign in with phone and PIN.'], 401);
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
