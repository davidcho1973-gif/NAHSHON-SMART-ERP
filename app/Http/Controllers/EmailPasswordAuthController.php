<?php

namespace App\Http\Controllers;

use App\Services\Auth\EmailPasswordAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class EmailPasswordAuthController extends Controller
{
    public function login(Request $request, EmailPasswordAuthService $service): RedirectResponse
    {
        if ($request->user()) {
            return redirect($request->user()->landingPath());
        }
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $result = $service->attempt($data['email'], $data['password'], $request);
        if ($result === 'setup') {
            return redirect()->route('password.setup');
        }
        if ($result === 'login') {
            return redirect($request->user()->landingPath());
        }

        return redirect()->route('login')->withInput(['email' => $data['email']])->withErrors([
            'email_login' => '로그인할 수 없습니다. 이메일·비밀번호와 계정 활성 상태를 확인해 주세요. 반복 실패 시 15분 후 다시 시도하세요. / Unable to sign in. Check your details or ask your administrator. After repeated failures, wait 15 minutes.',
        ]);
    }

    public function setup(Request $request, EmailPasswordAuthService $service): View|RedirectResponse
    {
        $user = $request->user() ?? $service->setupUser($request);
        if (! $user || $user->account_status !== 'active') {
            return redirect()->route('login')->withErrors(['email_login' => '설정 시간이 만료되었습니다. 다시 로그인해 주세요. / Please sign in again.']);
        }

        return view('auth.password-setup', ['changing' => (bool) $user->password_set_at]);
    }

    public function store(Request $request, EmailPasswordAuthService $service): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', 'max:128', Password::min(8)->letters()->numbers()],
            'current_password' => [$request->user()?->password_set_at ? 'required' : 'nullable', 'string', 'max:128'],
        ], [
            'password.confirmed' => '두 비밀번호가 일치하지 않습니다. / Passwords do not match.',
            'password.min' => '영문과 숫자를 포함하여 8자 이상 입력하세요. / Use at least 8 characters with letters and numbers.',
        ]);
        $user = $service->completeSetup($data['password'], $request, $data['current_password'] ?? null);

        if (! $user && $request->user()) {
            return back()->withErrors(['password' => '현재 비밀번호 또는 계정 상태를 확인하세요. / Check your current password and account status.']);
        }

        return $user ? redirect($user->landingPath())
            : redirect()->route('login')->withErrors(['email_login' => '설정 시간이 만료되었거나 계정 상태가 변경되었습니다. 다시 로그인해 주세요. / Please sign in again.']);
    }
}
