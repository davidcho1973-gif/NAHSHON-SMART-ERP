<?php

namespace App\Http\Middleware;

use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** A personal-app device restores only app access, never the account's ERP session. */
class PersonalAppSession
{
    public function __construct(private readonly PersonalAppAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Real password/Google authentication explicitly replaces app-only proof.
        if ($request->user() && EmailPasswordAuthService::hasStrongAuthentication($request, $request->user())
            && ! WorkerDeviceSession::isDeviceOnly($request)) {
            PersonalAppAccessService::clearSession($request);

            return $next($request);
        }

        if ($this->restorable($request) && (! $request->user() || Auth::viaRemember())) {
            $this->access->restore($request, allowMatchingRemembered: true);
        }

        // Old Laravel remember cookies do not record whether phone digits created them.
        // They may retain app convenience, but cannot become a fresh ERP credential.
        if ($request->user() && Auth::viaRemember() && PurchaseAccess::eligible($request->user())
            && ! $request->session()->has(PersonalAppAccessService::SESSION)) {
            WorkerDeviceSession::markDeviceOnly($request);
            $request->session()->put(PersonalAppAccessService::LEGACY_SESSION, true);
        }

        if ($request->session()->get(PersonalAppAccessService::LEGACY_SESSION) === true) {
            return $this->allowed($request) ? $next($request) : $this->deny($request);
        }

        if (! $request->session()->has(PersonalAppAccessService::SESSION)) {
            return $next($request);
        }

        $device = $this->access->sessionDevice($request);
        if (! $device) {
            // A saved session must stop working as soon as the device/account is revoked.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Cookie::queue(Cookie::forget(PersonalAppAccessService::COOKIE));
            Cookie::queue(Cookie::forget(WorkerDeviceSession::COOKIE));

            if ($this->authenticationPath($request)) {
                return $next($request);
            }

            return $request->expectsJson()
                ? response()->json(['success' => false, 'code' => 'personal_app_device_revoked', 'error' => '앱 연결이 해제되었습니다. 새 QR로 연결하세요.'], 401)
                : redirect('/app')->with('status', '앱 연결이 해제되었습니다. 새 QR로 연결하세요.');
        }

        // The model is reloaded so role, employee status and site grants never come from a saved QR.
        Auth::setUser($device->user);
        if (! $this->allowed($request)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['success' => false, 'code' => 'personal_app_only', 'error' => '개인앱 연결입니다. ERP 업무는 별도로 로그인하세요.'], 403)
            : redirect('/attendance-app')->with('status', 'ERP 업무는 별도로 로그인하세요.');
    }

    private function restorable(Request $request): bool
    {
        // Never switch a signed-in account or consume a QR merely because a cookie exists.
        return ! $this->authenticationPath($request) && $this->allowed($request);
    }

    private function authenticationPath(Request $request): bool
    {
        return $request->is('app/connect/*', 'login', 'logout', 'auth/google', 'auth/google/callback', 'auth/password/login');
    }

    private function allowed(Request $request): bool
    {
        if ($request->is('app', 'app/install', 'app/connect/*', 'personal-app-access/*',
            'attendance-app', 'attendance-app/home', 'attendance-app/language', 'attendance-app/self-link',
            'attendance-app/punch', 'attendance-app/correction', 'attendance-app/manager-access',
            'attendance-app/purchase-requests', 'attendance-app/ask', 'attendance-app/docs', 'attendance-app/ops-room',
            'attendance-app/messages', 'attendance-app/messages/*', 'attendance-app/team/*', 'attendance-app/badge/*',
            'attendance-app/material-receipts', 'attendance-app/material-receipts/items', 'attendance-app/material-receipts/upload',
            'purchase-requests', 'purchase-requests/*', 'attendance-geo/ping', 'attendance-geo/status',
            'expense-app', 'expense-app/submit', 'expense-app/list', 'ask-api/question', 'docs-api/upload',
            'ops-api/voice', 'ops-api/photo', 'ops-api/photo/*', 'ops-api/trade-report/status', 'ops-api/trade-report/submit',
            'push/key', 'push/subscribe', 'push/unsubscribe', 'help', 'locale', 'logout', 'login',
            'auth/google', 'auth/google/callback', 'auth/password/login', '*.webmanifest')) {
            return true;
        }
        if (preg_match('#^attendance-app/material-receipts/\d+/confirm$#', $request->path())) {
            return $request->isMethod('POST');
        }

        // Only these six scoped field-record operations are used by the personal app.
        if ($request->isMethod('POST') && $request->is('smart-company-api/*')) {
            return in_array((string) $request->route('method'), [
                'api_opsIngest', 'api_getOpsJob', 'api_getOpsBatches',
                'api_getOpsBatch', 'api_updateOpsBatch', 'api_deleteOpsBatch',
            ], true);
        }

        return false;
    }
}
