<?php

namespace App\Support;

use App\Models\AuthEvent;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/** Site attendance device sessions never confer personal business-app access. */
final class WorkerDeviceSession
{
    /** 이 세션이 휴대폰(기기 토큰)만으로 열렸다는 표시. 정식 로그인을 하면 사라진다. */
    public const FLAG = 'worker_device_only';

    /** 기기 토큰을 담는 쿠키 — localStorage 와 달리 서버가 첫 요청에서 바로 읽는다. */
    public const COOKIE = 'worker_device';

    /** 쿠키 수명(일). 현장 사람이 몇 달 만에 앱을 열어도 다시 등록하게 만들지 않는다. */
    public const COOKIE_DAYS = 400;

    /**
     * 휴대폰만으로 들어온 세션인가.
     *
     * 로그인하지 않았으면 false 다 — 그건 «등급이 낮다» 가 아니라 «아직 아무도 아니다» 이고,
     * 그 판단은 auth 미들웨어가 한다.
     */
    public static function isDeviceOnly(Request $request): bool
    {
        return $request->user() !== null && $request->session()->get(self::FLAG) === true;
    }

    /** 휴대폰으로 열었다고 표시한다. */
    public static function markDeviceOnly(Request $request): void
    {
        // Switching to a weak worker entry must discard proofs from an earlier account/session.
        $request->session()->forget([
            EmailPasswordAuthService::STRONG_AUTH_SESSION,
            EmailPasswordAuthService::SETUP_SESSION,
            PersonalAppAccessService::SESSION,
            PersonalAppAccessService::LEGACY_SESSION,
        ]);
        $request->session()->put(self::FLAG, true);
    }

    /** 정식 로그인을 완료했으므로 기기 전용 표시를 지운다. */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::FLAG);
    }

    /** Verified site-QR device tokens may open only an active worker/self session. */
    public static function openFor(Request $request, string $token): bool
    {
        $employee = WorkerDevice::resolve($token, requireVerified: true);
        if ($employee === null || $employee->employment_status !== 'active') {
            return false;
        }

        $user = $employee->user;
        if (! self::mayEnterWithDeviceAlone($user)) {
            return false;
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        self::markDeviceOnly($request);

        try {
            AuthEvent::record('login_ok', user: $user, method: 'device', request: $request);
        } catch (\Throwable $e) {
            report($e); // 기록이 실패해도 출퇴근은 막지 않는다.
        }

        return true;
    }

    /** Legacy worker-only entry; manager and foreman app access requires normal login. */
    public static function openForApp(Request $request, Employee $employee): bool
    {
        // Old callers must not turn a phone lookup into a management-account login.
        if ($employee->employment_status !== 'active'
            || ($employee->user && ! self::mayEnterWithDeviceAlone($employee->user))) {
            return false;
        }

        $user = $employee->user ?? User::query()->create([
            'name' => $employee->name,
            'email' => null,
            'password' => Str::random(64),
            'employee_id' => $employee->id,
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
            'allowed_company_id' => $employee->company_id,
            'allowed_site_id' => $employee->site_id,
            'allowed_team_id' => $employee->team_id,
        ]);

        if ($user->account_status !== 'active') {
            return false;
        }

        // A Laravel recaller restores only the user, not this app-only session boundary.
        // Management roles cannot use this worker-only entry.
        $remember = self::mayEnterWithDeviceAlone($user);
        Auth::login($user, remember: $remember);
        if (! $remember) {
            Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));
        }
        $request->session()->regenerate();
        self::markDeviceOnly($request);

        try {
            AuthEvent::record('login_ok', user: $user, method: 'phone4', request: $request);
        } catch (\Throwable $e) {
            report($e); // 기록이 실패해도 출퇴근은 막지 않는다.
        }

        return true;
    }

    public static function mayEnterWithDeviceAlone(?User $user): bool
    {
        return $user !== null
            && $user->access_role === 'worker'
            && $user->access_scope === 'self'
            && $user->account_status === 'active';
    }
}
