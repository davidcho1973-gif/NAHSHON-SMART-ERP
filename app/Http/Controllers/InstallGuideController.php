<?php

namespace App\Http\Controllers;

use App\Support\PhoneInstallGuide;
use App\Support\WorkerLang;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 「홈 화면에 추가」 안내서 — 작업자가 혼자 따라 할 수 있게.
 *
 * 로그인을 걸지 않는다. 이 화면을 가장 필요로 하는 사람은 <b>아직 앱에 못 들어간</b>
 * 사람이고, 그 앞에 로그인을 놓으면 안내서까지 못 본다.
 */
class InstallGuideController extends Controller
{
    public function show(Request $request): View
    {
        // 폰을 직접 고를 수도 있게 한다 — 판정이 틀렸을 때 사람이 넘어갈 길이 있어야 하고,
        // 관리자가 «아이폰 쓰는 사람은 이 화면» 이라고 링크를 보낼 수도 있어야 한다.
        $asked = (string) $request->query('phone', '');
        $case = in_array($asked, PhoneInstallGuide::ALL, true)
            ? $asked
            : PhoneInstallGuide::detect($request->userAgent());

        return view('worker-app.install-guide', [
            'case' => $case,
            'detected' => $asked === '',
            'lang' => WorkerLang::resolve($request->query('lang', $request->cookie('app_locale'))),
            'langOptions' => WorkerLang::OPTIONS,
            'dict' => WorkerLang::installGuide(),
            'order' => PhoneInstallGuide::ALL,
        ]);
    }
}
