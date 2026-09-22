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

    /**
     * 이메일 + (전화번호 뒷 4자리 또는 본인이 정한 비밀번호) 로 들어온다.
     *
     * ── 4자리가 계속 통하는 이유 (2026-09-22, 오너 지시) ────────────────
     * 처음에는 4자리를 «비밀번호를 정하는 5분» 을 여는 열쇠로만 썼다. 한 번 쓰고 닫히는
     * 문이었다. 오너가 <b>계속 쓸 수 있게</b> 하라고 정했다 — 현장 관리자에게 비밀번호를
     * 하나 더 외우게 하는 것이 실제로는 «못 들어온다» 로 끝나기 때문이다.
     *
     * 그래서 4자리는 상시 열쇠가 된다. 비밀번호를 정한 사람은 둘 다 쓸 수 있다.
     *
     * 4자리는 10,000 가지뿐이라 그 자체로는 약하다. 대신 아래 두 가지가 지킨다:
     *   · 5번 틀리면 15분 잠긴다 — 전부 시도하려면 500시간이 걸려 원격 추측은 사실상 막힌다.
     *   · 이 길로 들어온 계정은 알림으로 한 번 올라간다.
     *
     * 남는 위험은 <b>번호를 아는 사람</b>이다(명부·단톡방에 적혀 있다). 그건 잠금으로
     * 막을 수 없고, 알림으로 «보이게» 하는 것이 이 설계가 할 수 있는 전부다.
     */
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

            // 둘 중 어느 쪽이든 된다. 비밀번호를 정했다고 4자리가 닫히지 않는다.
            $initial = $this->initialDigits($user);
            $byDigits = $initial !== null && hash_equals($initial, $password);
            $byPassword = $user->password_set_at && Hash::check($password, $user->password);
            $valid = $byDigits || $byPassword;

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

            // 바로 들어간다. 예전에는 4자리면 «비밀번호를 정하라» 는 화면을 한 번 거쳤는데,
            // 4자리를 계속 쓰게 된 지금 그 화면은 매번 나오는 군더더기가 된다.
            return ['result' => 'login', 'user' => $user, 'digits' => $byDigits];
        });

        if ($result['result'] === 'login') {
            $this->signIn($result['user'], $request);
            if ($result['digits'] ?? false) {
                $this->announcePhoneDigitSignIn($result['user'], $request);
            }
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
        }

        return $user;
    }

    /**
     * 전화번호 뒷 4자리로 계정에 들어왔다 — 알린다.
     *
     * 4자리는 현장에서 반쯤 공개된 값이다(명부·단톡방에 적혀 있다). 상시 열쇠로 쓰기로
     * 한 이상 잠금만으로는 «번호를 아는 사람» 을 막을 수 없다. 그래서 이 길로 들어온
     * 계정이 <b>보이게</b> 한다 — 이 설계가 그 위험에 대해 할 수 있는 것은 그것뿐이다.
     *
     * 계정당 한 번만 올린다(fingerprint). 로그인할 때마다 울리면 곧 안 읽는 알림이 되고,
     * 안 읽는 알림은 없는 알림이다.
     *
     * 알림이 실패해도 로그인은 되돌리지 않는다 — 그 사람은 이미 들어와 있다.
     */
    private function announcePhoneDigitSignIn(User $user, Request $request): void
    {
        try {
            app(UnifiedAlertService::class)->emit("signed-in-with-phone-digits:{$user->id}", [
                'company_id' => $user->allowed_company_id,
                'site_id' => $user->allowed_site_id,
                'employee_id' => $user->employee_id,
                'source_module' => 'HR',
                'source_type' => User::class,
                'source_id' => (string) $user->id,
                'event_type' => 'signed_in_with_phone_digits',
                'severity' => 'warning',
                'title' => "전화 뒷 4자리로 로그인: {$user->name}",
                'content' => sprintf(
                    '%s (%s) 님의 계정이 등록 이메일과 전화번호 뒷 4자리로 열렸습니다. '
                    .'뒷 4자리는 명부·단톡방에 적혀 있는 값이라 아는 사람이 여럿입니다 — 본인이 맞는지 '
                    .'한 번 확인해 주세요. 더 단단히 하려면 그분이 로그인한 뒤 비밀번호를 정하면 됩니다. '
                    .'첫 접속 %s · IP %s',
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
        // password_set_at 을 더 이상 보지 않는다 — 비밀번호를 정했다고 4자리가 닫히면
        // «계속 쓸 수 있게» 가 되지 않는다. PIN 계정(작업자·반장)만 계속 막는다.
        if ($user->hasPin()) {
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
