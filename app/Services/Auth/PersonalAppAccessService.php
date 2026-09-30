<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\AuthSetupToken;
use App\Models\PersonalAppDevice;
use App\Models\User;
use App\Support\PurchaseAccess;
use App\Support\QrSvg;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

class PersonalAppAccessService
{
    public const PURPOSE = 'personal_app_device';

    public const SESSION = 'personal_app_device_id';

    public const LEGACY_SESSION = 'legacy_manager_app_only';

    public const COOKIE = 'personal_app_device';

    public const DAYS = 400;

    public const QR_MINUTES = 15;

    public static function eligible(?User $user): bool
    {
        return PurchaseAccess::eligible($user)
            && (! $user->employee_id || $user->employee?->employment_status === 'active')
            && ($user->access_role === 'super_admin' || $user->purchase_request_enabled || $user->purchase_buy_enabled);
    }

    public function canManage(Request $request): bool
    {
        $user = $request->user();
        if (! self::eligible($user) || $user->access_role !== 'super_admin') {
            return false;
        }

        return (! WorkerDeviceSession::isDeviceOnly($request)
                && EmailPasswordAuthService::hasStrongAuthentication($request, $user))
            || $this->sessionDevice($request) !== null;
    }

    public function assertManage(Request $request): void
    {
        if ($this->canManage($request)) {
            return;
        }
        $reauth = $request->user()?->access_role === 'super_admin';
        abort(response()->json(['success' => false,
            'code' => $reauth ? 'personal_app_reauthentication_required' : 'personal_app_access_denied',
            'error' => $reauth ? 'QR 발급은 다시 로그인하거나 연결된 개인앱에서 이용하세요.' : '수퍼관리자만 개인 QR을 발급할 수 있습니다.',
        ], 403));
    }

    public function users(Request $request): array
    {
        $this->assertManage($request);
        $users = User::query()->with('employee')->where('account_status', 'active')
            ->whereIn('access_role', PurchaseAccess::ELIGIBLE_ROLES)
            ->where(fn ($q) => $q->where('access_role', 'super_admin')->orWhere('purchase_request_enabled', true)->orWhere('purchase_buy_enabled', true))
            ->orderBy('name')->get()->filter(fn (User $user) => self::eligible($user));
        $devices = PersonalAppDevice::query()->whereIn('user_id', $users->pluck('id'))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest('last_used_at')->get()->groupBy('user_id');

        return ['success' => true, 'qr_ttl_minutes' => self::QR_MINUTES, 'users' => $users->map(fn (User $user) => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $user->access_role, 'role_label' => User::ROLE_LABELS_KO[$user->access_role] ?? $user->access_role,
            'scope_label' => User::SCOPE_LABELS_KO[$user->access_scope] ?? $user->access_scope,
            'purchase_request_enabled' => PurchaseAccess::canRequest($user), 'purchase_buy_enabled' => PurchaseAccess::hasBuyerPermission($user),
            'devices' => ($devices[$user->id] ?? collect())->map(fn (PersonalAppDevice $device) => [
                'id' => $device->id, 'label' => $device->label, 'last_used_at' => $device->last_used_at?->toIso8601String(),
                'expires_at' => $device->expires_at->toIso8601String(), 'revoked_at' => null,
            ])->values()->all(),
        ])->values()->all()];
    }

    public function issue(Request $request, int $userId): array
    {
        $this->assertManage($request);

        return DB::transaction(function () use ($request, $userId) {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            abort_unless(self::eligible($user), 422, '승인된 관리자 계정을 선택하세요.');
            // Reissuing this purpose must not consume another account recovery flow.
            AuthSetupToken::query()->where('user_id', $user->id)->where('purpose', self::PURPOSE)->whereNull('used_at')->delete();
            $token = Str::random(64);
            $expires = now()->addMinutes(self::QR_MINUTES);
            AuthSetupToken::create(['user_id' => $user->id, 'token_hash' => AuthSetupToken::hash($token),
                'purpose' => self::PURPOSE, 'issued_by_id' => $request->user()->id, 'expires_at' => $expires]);
            $url = route('personal-app.connect', ['token' => $token]);
            AuthEvent::record('personal_app_qr_issued', user: $user, actor: $request->user(), method: 'personal_app_qr', request: $request);

            return ['success' => true, 'user_id' => $user->id, 'name' => $user->name,
                'url' => $url, 'qr_svg' => QrSvg::svg($url, 320, 4), 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function preview(Request $request, string $token): array
    {
        $row = AuthSetupToken::query()->where('purpose', self::PURPOSE)->where('token_hash', AuthSetupToken::hash($token))->first();
        $status = ! $row || $row->expires_at->lte(now()) ? 'expired' : ($row->used_at ? 'used' : 'ready');
        if ($status === 'ready' && (! self::eligible($row->user) || ! $this->validIssuer($row))) {
            $status = 'denied';
        }

        return ['status' => $status, 'name' => $status === 'ready' ? $row->user->name : '',
            'expiresAt' => $row?->expires_at?->toIso8601String(), 'token' => $status === 'ready' ? $token : null,
            'accountSwitch' => $status === 'ready' && $this->needsAccountSwitch($request, $row->user_id)];
    }

    public function connect(Request $request, string $token): void
    {
        [$user, $device, $credential] = DB::transaction(function () use ($request, $token) {
            $row = AuthSetupToken::query()->where('purpose', self::PURPOSE)->where('token_hash', AuthSetupToken::hash($token))->lockForUpdate()->first();
            abort_unless($row && ! $row->used_at && $row->expires_at->gt(now()), 410, 'QR이 만료되었거나 이미 사용되었습니다. 새 QR을 발급받으세요.');
            $user = User::query()->lockForUpdate()->find($row->user_id);
            abort_unless(self::eligible($user) && $this->validIssuer($row), 403, '계정 또는 앱 권한이 변경되었습니다. 수퍼관리자에게 문의하세요.');
            abort_if($this->needsAccountSwitch($request, $user->id) && ! $request->boolean('confirm_switch'), 409, '다른 계정으로 연결하려면 계정 전환을 확인하세요.');
            $row->consume();
            $credential = Str::random(64);
            $device = PersonalAppDevice::create(['user_id' => $user->id, 'issued_by_id' => $row->issued_by_id,
                'token_hash' => PersonalAppDevice::hash($credential), 'label' => Str::limit((string) $request->userAgent(), 160, ''),
                'last_used_at' => now(), 'expires_at' => now()->addDays(self::DAYS)]);
            // A browser changing accounts must not leave its previous persistent credential live.
            $old = (string) $request->cookie(self::COOKIE, '');
            if ($old !== '') {
                PersonalAppDevice::where('token_hash', PersonalAppDevice::hash($old))->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            AuthEvent::record('personal_app_device_connected', user: $user, actor: User::find($row->issued_by_id), method: 'personal_app_qr', request: $request, note: 'device:'.$device->id);

            return [$user, $device, $credential];
        });
        $this->open($request, $user, $device);
        Cookie::queue($this->cookie($request, $credential));
        Cookie::queue(Cookie::forget(WorkerDeviceSession::COOKIE));
        Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));
    }

    private function validIssuer(AuthSetupToken $token): bool
    {
        $issuer = $token->issued_by_id ? User::find($token->issued_by_id) : null;

        return self::eligible($issuer) && $issuer->access_role === 'super_admin';
    }

    private function needsAccountSwitch(Request $request, int $userId): bool
    {
        $current = $request->user()?->id ?? $this->cookieDevice($request)?->user_id;

        return $current !== null && (int) $current !== $userId;
    }

    public function cookie(Request $request, string $token): HttpCookie
    {
        return Cookie::make(self::COOKIE, $token, self::DAYS * 24 * 60, '/', null,
            $request->isSecure() || app()->environment('production'), true, false, 'lax');
    }

    public function cookieDevice(Request $request): ?PersonalAppDevice
    {
        $token = (string) $request->cookie(self::COOKIE, '');

        return $token === '' ? null : $this->usable(PersonalAppDevice::with('user.employee')->where('token_hash', PersonalAppDevice::hash($token))->first());
    }

    public function sessionDevice(Request $request): ?PersonalAppDevice
    {
        $id = $request->session()->get(self::SESSION);
        if (! $id || ! $request->user()) {
            return null;
        }
        $device = $this->usable(PersonalAppDevice::with('user.employee')->find($id));

        return $device && $device->user_id === $request->user()->id ? $device : null;
    }

    private function usable(?PersonalAppDevice $device): ?PersonalAppDevice
    {
        if (! $device || $device->revoked_at || $device->expires_at->lte(now())) {
            return null;
        }
        if (! self::eligible($device->user)) {
            $device->update(['revoked_at' => now()]);

            return null;
        }

        return $device;
    }

    public function restore(Request $request, bool $allowMatchingRemembered = false): bool
    {
        $current = $request->user();
        if ($current && (! $allowMatchingRemembered || ! Auth::viaRemember())) {
            return false;
        }
        $device = $this->cookieDevice($request);
        if (! $device || ($current && $current->id !== $device->user_id)) {
            return false;
        }
        $this->open($request, $device->user, $device);
        // The app device is now the persistent credential; an old ERP recaller must not compete with it.
        Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));

        return true;
    }

    private function open(Request $request, User $user, PersonalAppDevice $device): void
    {
        self::clearSession($request);
        Auth::login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->forget([EmailPasswordAuthService::STRONG_AUTH_SESSION, EmailPasswordAuthService::SETUP_SESSION, 'url.intended']);
        WorkerDeviceSession::markDeviceOnly($request);
        $request->session()->put(self::SESSION, $device->id);
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subMinutes(5))) {
            $device->update(['last_used_at' => now()]);
        }
    }

    public static function clearSession(Request $request): void
    {
        $request->session()->forget([self::SESSION, self::LEGACY_SESSION]);
    }

    public function revoke(Request $request, int $deviceId): void
    {
        $this->assertManage($request);
        $device = PersonalAppDevice::findOrFail($deviceId);
        if (! $device->revoked_at) {
            $device->update(['revoked_at' => now()]);
            AuthEvent::record('personal_app_device_revoked', user: $device->user, actor: $request->user(),
                method: 'personal_app_qr', request: $request, note: 'device:'.$device->id);
        }
    }

    public function logout(Request $request): void
    {
        $id = (int) $request->session()->get(self::SESSION, 0);
        $token = (string) $request->cookie(self::COOKIE, '');
        if ($id || $token !== '') {
            PersonalAppDevice::query()->where(function ($q) use ($id, $token): void {
                $q->where('id', $id);
                if ($token !== '') {
                    $q->orWhere('token_hash', PersonalAppDevice::hash($token));
                }
            })->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        self::clearSession($request);
        Cookie::queue(Cookie::forget(self::COOKIE));
        Cookie::queue(Cookie::forget(WorkerDeviceSession::COOKIE));
    }
}
