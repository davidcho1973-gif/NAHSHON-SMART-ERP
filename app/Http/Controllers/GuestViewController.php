<?php

namespace App\Http\Controllers;

use App\Models\GuestLink;
use App\Services\Admin\GuestLinkService;
use App\Support\QrSvg;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * 손님 전용 현황 화면 — 로그인 없이, 링크의 현장 하나만.
 *
 * 링크가 곧 열쇠이므로 화면은 세 가지 상태뿐이다:
 *  - 토큰이 아예 없는 주소 → 404 (있는지 없는지조차 알려 주지 않는다)
 *  - 있었지만 회수·만료된 링크 → 안내 화면 (410 — 새 링크를 요청하라고 알려 준다)
 *  - 살아 있는 링크 → 그 현장의 공정 현황
 */
class GuestViewController extends Controller
{
    public function show(string $token): View|Response
    {
        $link = GuestLink::query()->with('site')->where('token', $token)->first();

        if (! $link || ! $link->site) {
            abort(404);
        }

        if (! $link->isUsable()) {
            return response()->view('guest.status', ['expired' => true, 'snapshot' => null], 410);
        }

        $link->increment('view_count', 1, ['last_viewed_at' => now()]);

        return view('guest.status', [
            'expired' => false,
            'snapshot' => app(GuestLinkService::class)->snapshot($link->site),
        ]);
    }

    /** 발급한 링크의 QR 인쇄 카드 — 관리자가 손님에게 화면을 보여 주거나 종이로 건넨다. */
    public function qr(GuestLink $link): View
    {
        abort_unless(app(GuestLinkService::class)->canManage(), 403);

        return view('guest.qr', [
            'link' => $link->load('site'),
            'url' => $link->url(),
            'qrImage' => QrSvg::dataUri($link->url(), 320),
        ]);
    }
}
