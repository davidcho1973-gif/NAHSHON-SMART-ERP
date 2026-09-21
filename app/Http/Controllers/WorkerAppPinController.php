<?php

namespace App\Http\Controllers;

use App\Models\AuthSetupToken;
use App\Services\Auth\PinAuthService;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 휴대폰으로 들어온 작업자가 <b>메시지·문서</b>를 열 때만 거치는 한 단계.
 *
 * 출퇴근은 이 화면을 안 거친다. 여기는 남의 글이 있는 곳으로 가는 문이다.
 * 한 번 넣으면 세션이 살아 있는 동안 다시 묻지 않는다.
 *
 * PIN 을 아직 안 정한 사람은 정하러 보낸다 — 그 화면(pin.setup)은 이미 있고,
 * 여기서 새로 만들면 PIN 규칙(너무 쉬운 번호 거절 등)이 두 벌이 된다.
 */
class WorkerAppPinController extends Controller
{
    public function __construct(private readonly PinAuthService $pins) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // 이미 PIN 을 통과했거나 정식 로그인이면 이 화면은 지나칠 곳이다.
        if (! WorkerDeviceSession::isDeviceOnly($request)) {
            return redirect()->to($this->nextUrl($request));
        }

        if (! $user?->hasPin()) {
            // 아직 없는 사람 — 지금 정하면 된다. 이 휴대폰이 본인임은 기기 토큰이 이미 증명했다.
            return redirect()->to($this->pins->issueSetupLink($user, AuthSetupToken::PURPOSE_ACTIVATION));
        }

        return view('worker-app.pin', [
            'next' => $this->nextUrl($request),
            'error' => null,
        ]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! WorkerDeviceSession::isDeviceOnly($request)) {
            return redirect()->to($this->nextUrl($request));
        }

        // 시도 횟수·잠금 규칙은 PinAuthService 한 곳에 있다.
        $result = $this->pins->verifyFor($user, (string) $request->input('pin', ''), $request);

        if (! ($result['success'] ?? false)) {
            return view('worker-app.pin', [
                'next' => $this->nextUrl($request),
                'error' => $result['error'] ?? '번호가 맞지 않습니다.',
            ]);
        }

        WorkerDeviceSession::clear($request);

        return redirect()->to($this->nextUrl($request));
    }

    /**
     * 돌아갈 곳 — 막히기 전에 가려던 화면.
     *
     * 반드시 이 사이트 안이어야 한다. 주소를 그대로 믿고 보내면 「메시지 보기」 링크 하나로
     * 바깥 사이트에 떨궈 놓고 로그인 화면을 흉내 낼 수 있다.
     */
    private function nextUrl(Request $request): string
    {
        $next = (string) $request->input('next', '');
        $home = route('attendance-app.index');

        if ($next === '') {
            return $home;
        }

        $host = parse_url($next, PHP_URL_HOST);

        return $host === null || $host === $request->getHost() ? $next : $home;
    }
}
