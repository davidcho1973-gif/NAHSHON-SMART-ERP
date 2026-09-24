<?php

namespace App\Http\Controllers;

use App\Support\WorkerDeviceSession;
use App\Support\WorkerLang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * 작업자가 앱으로 들어오는 문 — <b>로그인 화면을 거치지 않는다.</b>
 *
 * ── 고친 것 ────────────────────────────────────────────────────────────
 * 작업자가 「작업자 앱 열기」 를 누르면 ERP 로그인 화면이 떠서 <b>이메일과 비밀번호</b>를
 * 물었다. 작업자에게는 둘 다 없다. 거기가 막다른 길이었다.
 *
 * 원인은 «앱이 로그인을 요구한다» 가 아니라, <b>작업자를 알아보는 방법이 이미 있는데
 * 앱이 그걸 안 썼다</b> 는 것이다. 등록할 때 그 휴대폰에 기기 토큰을 발급해 두었고,
 * 게이트는 그것만으로 사람을 알아본다. 앱만 사무직과 같은 문을 쓰고 있었다.
 *
 * 그래서 게이트와 같은 열쇠로 앱도 연다. 대신 그렇게 들어온 세션에는 표시를 남겨
 * (WorkerDeviceSession) 메시지·문서처럼 남의 글이 있는 곳에서만 PIN 을 한 번 받는다.
 *
 * ── 토큰이 두 군데 있는 이유 ──────────────────────────────────────────
 * 원래 토큰은 localStorage 에만 있었다. 그건 서버가 못 읽으므로 첫 요청에서 바로
 * 알아볼 수 없고, 화면을 한 번 그린 뒤 JS 가 건네줘야 한다. 그래서 한 번 건네받으면
 * <b>쿠키로도 심어</b> 다음부터는 서버가 첫 요청에서 바로 알아본다. localStorage 가
 * 여전히 원본이다 — 게이트가 그것을 쓰고, 공용 폰 판정도 그쪽에 걸려 있다.
 */
class WorkerAppEntryController extends Controller
{
    /**
     * 앱 문 앞.
     *
     * 이미 로그인돼 있으면 그대로 들여보낸다. 쿠키에 기기 토큰이 있으면 그것으로 들어간다.
     * 둘 다 없으면 <b>ERP 로그인 화면이 아니라</b> 작업자가 읽을 수 있는 안내를 보여 주고,
     * 그 화면의 JS 가 localStorage 의 토큰을 서버로 건넨다.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('attendance-app.index');
        }

        $cookie = (string) $request->cookie(WorkerDeviceSession::COOKIE, '');
        if ($cookie !== '' && $this->signIn($request, $cookie)) {
            return redirect()->route('attendance-app.index');
        }

        return view('worker-app.entry', [
            'lang' => WorkerLang::resolve($request->query('lang', $request->cookie('app_locale'))),
            'langOptions' => WorkerLang::OPTIONS,
            // 토큰을 못 찾은 채로 이 화면이 다시 뜨면, 안내 문구를 바꿔 보여 준다.
            'failed' => $request->boolean('retry'),
        ]);
    }

    /**
     * 화면의 JS 가 localStorage 에서 꺼낸 토큰을 건넨다.
     *
     * 토큰이 맞으면 로그인시키고 쿠키로도 심는다(다음부터는 이 왕복이 없다).
     */
    public function device(Request $request): RedirectResponse
    {
        $token = trim((string) $request->input('device_token', ''));

        if ($token === '' || ! $this->signIn($request, $token)) {
            // 실패해도 로그인 화면으로 보내지 않는다 — 그게 이 화면이 생긴 이유다.
            return redirect()->route('worker-app.entry', ['retry' => 1]);
        }

        return redirect()->route('attendance-app.index')
            ->withCookie(Cookie::make(
                WorkerDeviceSession::COOKIE,
                $token,
                60 * 24 * WorkerDeviceSession::COOKIE_DAYS,
                httpOnly: true,
                sameSite: 'Lax',
            ));
    }

    /**
     * 토큰 하나로 이 사람이 되게 한다.
     *
     * 작업자 본인 범위 계정만 통과한다(WorkerDeviceSession). 벽에 붙은 QR 로 등록한
     * 휴대폰 한 대가 관리자 열쇠가 되면 안 된다.
     */
    private function signIn(Request $request, string $token): bool
    {
        return WorkerDeviceSession::openFor($request, $token);
    }
}
