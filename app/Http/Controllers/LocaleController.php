<?php

namespace App\Http\Controllers;

use App\Support\AppLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 「이 사람은 어느 말로 보겠다고 했는가」 를 받아 두는 단 한 곳.
 *
 * 로그인 전에도 눌린다 — 현장 QR 화면, 작업자 앱 문, 로그인 화면이 모두 로그인 전이다.
 * 그래서 이 문은 공개다. 받는 값은 세 가지뿐이고(ko·en·es) 그 밖은 버린다.
 *
 * 로그인한 사람이면 본인 언어까지 바꾼다. 쿠키만 두면 폰을 바꿨을 때 다시 한국어로
 * 돌아가고, 그 사람은 «또 한글이네» 를 겪는다.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lang' => ['required', 'string', 'in:'.implode(',', AppLocale::SUPPORTED)],
        ]);

        $locale = (string) $data['lang'];

        $employee = $request->user()?->employee;
        if ($employee && $employee->preferred_language !== $locale) {
            $employee->forceFill(['preferred_language' => $locale])->save();
        }

        return response()
            ->json(['success' => true, 'lang' => $locale])
            ->cookie(AppLocale::COOKIE, $locale, AppLocale::COOKIE_MINUTES);
    }
}
