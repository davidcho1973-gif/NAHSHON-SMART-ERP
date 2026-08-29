<?php

namespace App\Http\Controllers;

use App\Models\AuthSetupToken;
use App\Models\LoginDevice;
use App\Services\Auth\PinAuthService;
use App\Support\WorkerLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PIN 로그인 화면 — 구글 계정이 없는 현장 인력이 들어오는 문.
 *
 * 화면은 둘뿐이다: 초대 링크로 여는 <b>번호 정하기</b>, 그리고 기억된 폰에서 여는
 * <b>번호 넣기</b>. 값은 언제나 본인만 넣고, 서버는 해시만 갖는다.
 */
class PinAuthController extends Controller
{
    public function __construct(private readonly PinAuthService $pins) {}

    /** 초대·재설정 링크를 열었을 때 — 본인이 번호를 정하는 화면. */
    public function setupForm(Request $request, string $token): View
    {
        $row = AuthSetupToken::findUsable($token);

        return view('auth.pin-setup', [
            'token' => $token,
            'valid' => $row !== null,
            'userName' => $row?->user?->name,
            'lang' => WorkerLang::resolve($request->query('lang') ?: $row?->user?->employee?->preferred_language),
            'pinLength' => PinAuthService::PIN_LENGTH,
        ]);
    }

    /** 번호 저장 — 성공하면 이 폰을 기억하고 바로 들여보낸다. */
    public function setupStore(Request $request, string $token): JsonResponse
    {
        $result = $this->pins->completeSetup($token, (string) $request->input('pin', ''), $request);

        if (! ($result['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        return response()->json([
            'success' => true,
            'device_token' => $result['device_token'],
            'redirect' => $result['user']->landingPath(),
        ]);
    }

    /** 이 폰이 기억된 폰인가 — 맞을 때만 번호 입력창을 띄운다. */
    public function who(Request $request): JsonResponse
    {
        $user = LoginDevice::resolve((string) $request->input('device_token', ''));

        return response()->json([
            'known' => $user !== null && $this->pins->canSignIn($user) && $user->hasPin(),
            'name' => $user?->name,
        ]);
    }

    /** 기억된 폰 + 번호로 로그인. */
    public function login(Request $request): JsonResponse
    {
        $result = $this->pins->attempt(
            (string) $request->input('device_token', ''),
            (string) $request->input('pin', ''),
            $request,
        );

        if (! ($result['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        return response()->json(['success' => true, 'redirect' => $result['user']->landingPath()]);
    }

    /** 이 폰 기억 해제 — 폰을 바꾸거나 남에게 넘길 때. */
    public function forget(Request $request): JsonResponse
    {
        LoginDevice::forget((string) $request->input('device_token', ''));

        return response()->json(['success' => true]);
    }
}
