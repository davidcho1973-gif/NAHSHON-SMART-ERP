<?php

namespace App\Support;

use App\Models\AuthEvent;
use App\Models\User;
use App\Models\WorkerDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 「이 사람은 휴대폰만으로 들어왔는가」 — 작업자 세션의 등급을 정하는 단 한 곳.
 *
 * ── 왜 등급이 필요한가 ────────────────────────────────────────────────
 * 작업자 앱에는 출퇴근만 있는 게 아니다. <b>메시지</b>와 <b>문서</b>가 같이 있다.
 * (급여는 이미 빠져 있다.)
 *
 * 출퇴근은 매일 여러 번 하는 일이라 한 단계라도 있으면 안 찍힌다 — 안 찍힌 출퇴근은
 * 없는 근무가 되고, 그건 그 사람 임금이다. 그래서 휴대폰을 기억한 것만으로 연다.
 *
 * 메시지·문서는 다르다. 하루에 몇 번 여는 곳이고, 거기 있는 글은 <b>남의 것</b>이다.
 * 폰을 빌려주거나 잃어버렸을 때 출퇴근 화면이 열리는 것과 현장 대화가 통째로 읽히는
 * 것은 무게가 다르다. 그쪽만 PIN 네 자리를 한 번 받는다.
 *
 * 그래서 세션에 「휴대폰만으로 들어왔다」 는 표시를 남기고, 민감한 곳에서 그 표시를 본다.
 * 표시를 세션 한 곳에만 두는 이유: 화면마다 따로 판단하면 새 화면을 붙일 때 빠뜨린다.
 */
final class WorkerDeviceSession
{
    /** 이 세션이 휴대폰(기기 토큰)만으로 열렸다는 표시. PIN 을 통과하면 사라진다. */
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
        $request->session()->put(self::FLAG, true);
    }

    /** PIN 을 통과했다 — 이제 남의 글이 보이는 곳도 열어 준다. */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::FLAG);
    }

    /**
     * 이 계정이 휴대폰만으로 들어와도 되는 계정인가.
     *
     * <b>작업자 본인 범위</b>만 허용한다. 관리자·소장 계정이 이 길로 들어오면, 벽에 붙은
     * QR 로 등록한 폰 한 대가 곧 관리자 열쇠가 된다 — 그 계정들은 볼 수 있는 것이
     * 자기 기록만이 아니다. 권한이 한 칸이라도 넓으면 PIN 이나 정식 로그인을 거친다.
     */
    /**
     * 기기 토큰 하나로 그 사람의 세션을 연다 — «휴대폰만으로 들어왔다» 등급으로.
     *
     * 이 판단이 화면마다 적혀 있으면 새 입구를 붙일 때 한 군데가 빠진다. 실제로
     * 등록 직후에는 토큰만 발급하고 세션은 열지 않아서, 방금 등록한 사람이 자기
     * PIN 설정 화면(auth 뒤에 있다)에 들어가지 못했다.
     *
     * 통과 조건은 한 곳에 모은다: 검증된 토큰 · 재직 중 · 작업자 본인 범위 계정.
     */
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

    public static function mayEnterWithDeviceAlone(?User $user): bool
    {
        return $user !== null
            && $user->access_role === 'worker'
            && $user->access_scope === 'self'
            && $user->account_status === 'active';
    }
}
