<?php

namespace App\Http\Middleware;

use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Retire QR credentials and retain the boundary for legacy remembered sessions. */
class PersonalAppSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $qrSession = $request->session()->has(PersonalAppAccessService::SESSION);
        $qrCookie = (string) $request->cookie(PersonalAppAccessService::COOKIE, '') !== '';
        $strong = $request->user() && EmailPasswordAuthService::hasStrongAuthentication($request, $request->user())
            && ! WorkerDeviceSession::isDeviceOnly($request);

        // Old credentials are cleanup inputs, never authentication inputs.
        if ($qrSession || $qrCookie) {
            app(PersonalAppAccessService::class)->logout($request);
            if ($qrSession && ! $strong) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            if (! $request->user() && ! $this->authenticationPath($request)) {
                return $request->expectsJson()
                    ? response()->json(['success' => false, 'code' => 'personal_app_qr_retired', 'error' => '관리자 개인 QR 기능이 종료되었습니다. 다시 로그인하세요.'], 401)
                    : redirect()->route('login', ['erp' => 1])->with('status', '관리자 개인 QR 기능이 종료되었습니다. Google 또는 이메일·비밀번호로 로그인하세요.');
            }
        }

        if ($strong) {
            PersonalAppAccessService::clearSession($request);

            return $next($request);
        }
        // Preserve the boundary for old remember cookies of unknown authentication origin.
        if ($request->user() && Auth::viaRemember() && PurchaseAccess::eligible($request->user())) {
            WorkerDeviceSession::markDeviceOnly($request);
            $request->session()->put(PersonalAppAccessService::LEGACY_SESSION, true);
        }
        if ($request->session()->get(PersonalAppAccessService::LEGACY_SESSION) === true) {
            return $this->allowed($request) ? $next($request) : $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        return RequireApprovedErpAccess::deny($request,
            'ERP에 접속하려면 이메일·비밀번호 또는 Google로 로그인하세요. 개인앱 연결은 유지됩니다.',
            'personal_app_only');
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
