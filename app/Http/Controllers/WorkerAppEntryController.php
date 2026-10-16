<?php

namespace App\Http\Controllers;

use App\Models\WorkerDevice;
use App\Support\PersonalAppAccess;
use App\Support\WorkerDeviceSession;
use App\Support\WorkerLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Old app icons recover safely; site attendance never grants business-app access. */
class WorkerAppEntryController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (PersonalAppAccess::authenticated($request)) {
            return redirect()->route('attendance-app.index');
        }
        if (PersonalAppAccess::eligible($request->user())) {
            return redirect()->route('login', ['app' => 1]);
        }

        $employee = $request->user()?->employee;
        if ($employee === null && $request->user() === null) {
            $token = (string) $request->cookie(WorkerDeviceSession::COOKIE, '');
            $employee = $token !== '' ? WorkerDevice::resolve($token, requireVerified: true) : null;
        }

        return view('worker-app.access', [
            'gateUrl' => PersonalAppAccess::gateUrl($employee),
            'loginUrl' => route('login', ['app' => 1]),
            'lang' => WorkerLang::resolve($request->query('lang', $request->cookie('app_locale'))),
        ]);
    }

    /** A stale localStorage device token may recover a site link, never a login. */
    public function device(Request $request): RedirectResponse
    {
        if (PersonalAppAccess::authenticated($request)) {
            return redirect()->route('attendance-app.index');
        }

        $token = trim((string) $request->input('device_token', ''));
        $employee = $token !== '' ? WorkerDevice::resolve($token, requireVerified: true) : null;
        $url = PersonalAppAccess::gateUrl($employee);

        return $url ? redirect()->to($url) : redirect()->route('worker-app.entry');
    }

    /** Phone suffix lookup belongs only to the site-scoped attendance QR flow. */
    public function find(Request $request): JsonResponse
    {
        return $this->signInRequired();
    }

    /** Retained for old clients: an employee ID must never mint an app session/device. */
    public function enter(Request $request): JsonResponse
    {
        return $this->signInRequired();
    }

    private function signInRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'personal_app_sign_in_required',
            'error' => '관리직원·반장은 이메일·비밀번호 또는 Google로 로그인하세요. 일반 작업자는 현장 QR로 출퇴근합니다.',
            'redirect' => route('login', ['app' => 1]),
        ], 403)->header('Cache-Control', 'no-store');
    }
}
