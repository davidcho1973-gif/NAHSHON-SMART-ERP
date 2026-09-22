<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmailPasswordAuthService
{
    public const SETUP_SESSION = 'email_password_setup';

    /** Initial phone digits only open a five-minute setup session, never an ERP session. */
    public function attempt(string $email, string $password, Request $request): string
    {
        $request->session()->forget(self::SETUP_SESSION);
        $result = DB::transaction(function () use ($email, $password, $request) {
            $user = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($email))])->lockForUpdate()->first();
            if (! $user || ! $this->active($user)) {
                return ['result' => 'denied'];
            }
            if ($user->password_login_locked_until?->isFuture()) {
                return ['result' => 'denied'];
            }

            $initial = $this->initialDigits($user);
            $valid = $user->password_set_at
                ? Hash::check($password, $user->password)
                : ($initial !== null && hash_equals($initial, $password));

            if (! $valid) {
                $failures = $user->password_login_locked_until ? 1 : $user->password_login_failures + 1;
                $user->forceFill([
                    'password_login_failures' => $failures,
                    'password_login_locked_until' => $failures >= 5 ? now()->addMinutes(15) : null,
                ])->save();
                AuthEvent::record('login_failed', user: $user, method: 'password', request: $request);

                return ['result' => 'denied'];
            }

            $user->forceFill(['password_login_failures' => 0, 'password_login_locked_until' => null])->save();

            return ['result' => $user->password_set_at ? 'login' : 'setup', 'user' => $user];
        });

        if ($result['result'] === 'setup') {
            $request->session()->regenerate();
            $request->session()->put(self::SETUP_SESSION, [
                'user_id' => $result['user']->id,
                'expires_at' => now()->addMinutes(5)->timestamp,
                // Bind setup to the contact values checked at the first step.
                'contact' => $this->contactFingerprint($result['user']),
            ]);
        } elseif ($result['result'] === 'login') {
            $this->signIn($result['user'], $request);
        }

        return $result['result'];
    }

    public function setupUser(Request $request): ?User
    {
        $session = $request->session()->get(self::SETUP_SESSION);
        if (! is_array($session) || ($session['expires_at'] ?? 0) <= now()->timestamp) {
            return null;
        }
        $user = User::find($session['user_id'] ?? 0);

        return $user && $this->active($user) && $this->initialDigits($user) !== null
            && hash_equals($this->contactFingerprint($user), (string) ($session['contact'] ?? '')) ? $user : null;
    }

    public function completeSetup(string $password, Request $request, ?string $currentPassword = null): ?User
    {
        $id = $request->user()?->id ?? $this->setupUser($request)?->id;
        if (! $id) {
            return null;
        }

        // 이 사람이 «전화번호 뒷 4자리» 로 들어온 것인지, 이미 로그인한 채로 비밀번호를
        // 더하는 것인지. 아래에서 알림을 올릴지 정하는 값이라 바뀌기 전에 붙잡아 둔다.
        $viaPhoneDigits = $request->user() === null;

        $user = DB::transaction(function () use ($id, $password, $request, $currentPassword) {
            $user = User::query()->lockForUpdate()->find($id);
            if (! $user || ! $this->active($user)) {
                return null;
            }
            // An authenticated Google/PIN user can add a password once. Anonymous setup
            // is rechecked under the row lock, so two sessions cannot consume it twice.
            if (! $request->user() && ! $this->setupUser($request)) {
                return null;
            }
            if ($user->password_set_at && (! $request->user() || ! Hash::check($currentPassword ?? '', $user->password))) {
                return null;
            }
            $user->forceFill([
                'password' => Hash::make($password),
                'password_set_at' => now(),
                'password_login_failures' => 0,
                'password_login_locked_until' => null,
                'remember_token' => Str::random(60),
            ])->save();
            AuthEvent::record('password_set', user: $user, method: 'password', request: $request);

            return $user;
        });

        $request->session()->forget(self::SETUP_SESSION);
        if ($user) {
            $this->signIn($user, $request);
            if ($viaPhoneDigits) {
                $this->announcePhoneDigitSetup($user, $request);
            }
        }

        return $user;
    }

    /**
     * 전화번호 뒷 4자리로 첫 비밀번호가 정해졌다 — 알린다.
     *
     * 4자리는 현장에서 반쯤 공개된 값이다(명부·단톡방에 적혀 있다). 그래서 이 문을 열어
     * 두는 대신, 열렸다는 사실이 <b>반드시 보이게</b> 한다. 남이 먼저 가로챘다면 본인은
     * 못 들어가게 되는데, 본인이 «안 들어가진다» 고 말할 때까지 기다리면 늦는다.
     *
     * 알림이 실패해도 비밀번호 설정은 되돌리지 않는다 — 그 사람은 이미 들어와 있다.
     */
    private function announcePhoneDigitSetup(User $user, Request $request): void
    {
        try {
            app(UnifiedAlertService::class)->emit("password-set-by-phone-digits:{$user->id}", [
                'company_id' => $user->allowed_company_id,
                'site_id' => $user->allowed_site_id,
                'employee_id' => $user->employee_id,
                'source_module' => 'HR',
                'source_type' => User::class,
                'source_id' => (string) $user->id,
                'event_type' => 'password_set_by_phone_digits',
                'severity' => 'warning',
                'title' => "비밀번호 설정: {$user->name} (전화 뒷 4자리로 들어옴)",
                'content' => sprintf(
                    '%s (%s) 님이 등록 이메일과 전화번호 뒷 4자리로 들어와 비밀번호를 처음 설정했습니다. '
                    .'본인이 한 것이 맞는지 확인해 주세요 — 본인이 아니라면 그 계정은 지금 남의 손에 있습니다. '
                    .'접속 시각 %s · IP %s',
                    $user->name,
                    $user->email,
                    now()->format('Y-m-d H:i'),
                    $request->ip() ?: '-',
                ),
                'action_url' => '/?view=employee-admin',
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function active(User $user): bool
    {
        return $user->account_status === 'active'
            && (! $user->employee_id || $user->employee?->employment_status === 'active');
    }

    /**
     * 첫 로그인에 쓰는 전화번호 뒷 4자리 — 이것으로 열리는 것은 <b>비밀번호를 정하는 5분</b>뿐이다.
     *
     * ── 구글 계정에도 여는 이유 (2026-09-22, 오너 지시) ───────────────────
     * 원래는 google_id 가 있으면 이 길을 막았다. 4자리는 약한 값이고, 이미 잘 쓰고 있는
     * 구글 계정에 굳이 약한 문을 하나 더 내 줄 이유가 없었기 때문이다.
     *
     * 그런데 <b>구글이 안 되는 관리자</b>에게는 그 판단이 «문이 하나도 없다» 가 된다.
     * 지메일이 없거나 회사 구글 로그인이 막힌 사람은 계정은 있는데 들어갈 수가 없었다.
     * 남은 길은 관리자에게 링크를 받는 것뿐인데, 그 관리자가 본인인 경우가 많다.
     *
     * 그래서 연다. 대신 약한 값 하나로 계정이 통째로 넘어가지 않게 세 가지를 함께 둔다:
     *   ① 열리는 것은 로그인이 아니라 «비밀번호를 정하는 5분» 이다.
     *   ② 비밀번호를 한 번 정하면 그 순간 이 문은 닫힌다(password_set_at).
     *   ③ 이 길로 첫 비밀번호가 정해지면 <b>알림이 올라간다</b> — 남이 가로챘다면
     *      본인이 못 들어가서 말하기 전에 그 사실이 먼저 보인다.
     *
     * PIN 계정(작업자·반장)은 계속 막는다. 그쪽에는 이미 쓰는 길이 따로 있고,
     * 4자리에 4자리를 더하는 것은 보탬이 되지 않는다.
     */
    private function initialDigits(User $user): ?string
    {
        if ($user->password_set_at || $user->hasPin()) {
            return null;
        }
        $employee = $user->employee;
        if (! $employee || Str::lower(trim((string) $employee->email)) !== Str::lower(trim($user->email))) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $employee->phone);

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? substr($digits, -4) : null;
    }

    private function contactFingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->email.'|'.$user->employee?->phone, (string) config('app.key'));
    }

    private function signIn(User $user, Request $request): void
    {
        Auth::login($user, true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuthEvent::record('login_success', user: $user, method: 'password', request: $request);
    }
}
