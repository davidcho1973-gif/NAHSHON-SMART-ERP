<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\User;
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

    private function active(User $user): bool
    {
        return $user->account_status === 'active'
            && (! $user->employee_id || $user->employee?->employment_status === 'active');
    }

    private function initialDigits(User $user): ?string
    {
        // Do not silently add a guessable fallback to established Google or PIN accounts.
        if ($user->password_set_at || filled($user->google_id) || $user->hasPin()) {
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
