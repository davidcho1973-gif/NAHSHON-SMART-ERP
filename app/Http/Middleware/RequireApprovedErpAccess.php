<?php

namespace App\Http\Middleware;

use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\Request;

/**
 * ERP 본화면은 <b>승인된 권한으로 정식 로그인</b> 한 사람에게만 열린다.
 *
 * ── 왜 이 문이 따로 필요한가 ──────────────────────────────────────────
 * 사장님 결정(2026-09-23): 작업자·반장·관리자 <b>모두</b> 전화번호 뒷 4자리만으로
 * 작업자 앱에 들어온다. 현장에서 외울 것을 없애기 위해서다.
 *
 * 그런데 그 문이 ERP 본화면까지 열면, 벽에 붙은 QR 과 남의 번호 뒷 4자리를 아는
 * 사람이 회사 전체 자료 앞에 서게 된다. 그래서 문을 둘로 나눈다 —
 *   · 뒷 4자리 → 작업자 앱(내 출퇴근·내 서류·현장 대화)
 *   · 구글 또는 이메일+비밀번호 → ERP 본화면. 그것도 슈퍼관리자가 권한을 준 계정만.
 *
 * ── 왜 화면마다 검사하지 않는가 ──────────────────────────────────────
 * 화면마다 적으면 새 화면을 붙일 때 반드시 하나를 빠뜨린다. 그리고 빠뜨린 그 한 화면이
 * 곧 전체가 열린 것과 같다. 세션에 «뒷 4자리로 들어왔다» 는 표시를 한 번 찍고
 * (WorkerDeviceSession), 그 표시를 <b>여기 한 곳</b>에서만 본다.
 *
 * 권한 «승인» 은 새로 만들지 않았다 — 이미 있는 것이 그것이다. 계정의 역할(access_role)은
 * 계정·권한 관리에서만 줄 수 있고, 줄 수 있는 역할의 범위는 주는 사람의 등급에 달려
 * 있다(UserAccessService::assignableRoles — 슈퍼관리자만 슈퍼관리자를 줄 수 있다).
 * 작업자·반장 역할은 그 승인이 아니라 «현장 인력» 이라는 뜻이므로 본화면이 열리지 않는다.
 */
class RequireApprovedErpAccess
{
    /** 본화면이 열리는 역할 — 사람이 권한 화면에서 <b>골라서</b> 준 것들이다. */
    public const ERP_ROLES = [
        'super_admin', 'admin', 'hr_manager', 'site_manager',
        'safety_manager', 'payroll', 'vendor_admin', 'client', 'viewer',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request); // 로그인 여부는 auth 미들웨어가 답한다.
        }

        // 뒷 4자리로 들어온 세션 — 권한이 무엇이든 본화면은 열리지 않는다.
        if (WorkerDeviceSession::isDeviceOnly($request)) {
            return self::deny($request, 'ERP에 접속하려면 이메일·비밀번호 또는 Google로 로그인하세요. 개인앱 연결은 유지됩니다.');
        }

        if (! in_array($user->access_role, self::ERP_ROLES, true)) {
            return self::deny($request, 'ERP 화면 접근 권한이 없습니다. 필요하시면 관리자에게 요청해 주세요.');
        }

        return $next($request);
    }

    public static function deny(Request $request, string $message, string $code = 'erp_access_denied')
    {
        $maySignIn = in_array($request->user()?->access_role, self::ERP_ROLES, true);
        if ($request->expectsJson() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['success' => false, 'error' => $message, 'code' => $code,
                'reauthenticate_url' => $maySignIn ? route('login', ['erp' => 1]) : null,
            ], 403);
        }

        // The app credential cannot open ERP, but approved managers need a visible
        // sign-in exit. Redirecting them back to the app trapped every ERP bookmark.
        if ($maySignIn) {
            return redirect()->route('login', ['erp' => 1])->with('status', $message);
        }

        return redirect()->route('attendance-app.index')->with('status', $message);
    }
}
