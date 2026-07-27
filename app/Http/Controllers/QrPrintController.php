<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\QrPosters;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 현장 QR 모아 인쇄 — 게이트 출퇴근·간편 등록(직접/협력사)·입사지원서 포스터를 한 번에 뽑는다.
 *
 * 현장에 처음 나갈 때 프린터 앞에서 탭 4개를 열고 각각 인쇄하는 일을 없애려는 화면이다.
 * 인쇄할 포스터는 체크박스로 고르고, 페이지마다 한 장씩 나뉘어 출력된다.
 */
class QrPrintController extends Controller
{
    public function sheet(Request $request, Site $site): View
    {
        $only = $request->query('only');
        $keys = is_string($only) && $only !== ''
            ? array_filter(array_map('trim', explode(',', $only)))
            : null;

        return view('qr-print.sheet', [
            'site' => $site,
            'posters' => QrPosters::many($site, $keys),
            'allKeys' => QrPosters::ORDER,
            'labels' => QrPosters::LABELS,
        ]);
    }
}
