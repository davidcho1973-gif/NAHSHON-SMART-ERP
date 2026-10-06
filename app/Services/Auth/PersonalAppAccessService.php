<?php

namespace App\Services\Auth;

use App\Models\PersonalAppDevice;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Compatibility cleanup only. Manager QR authentication was retired on 2026-10-05. */
class PersonalAppAccessService
{
    public const SESSION = 'personal_app_device_id';

    public const LEGACY_SESSION = 'legacy_manager_app_only';

    public const COOKIE = 'personal_app_device';

    public static function clearSession(Request $request): void
    {
        $request->session()->forget([self::SESSION, self::LEGACY_SESSION]);
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
