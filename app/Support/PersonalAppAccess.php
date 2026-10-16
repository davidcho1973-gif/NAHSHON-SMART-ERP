<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use Illuminate\Http\Request;

/** Personal business app access is independent of purchasing permissions. */
final class PersonalAppAccess
{
    public const ROLES = [...User::ADMIN_PANEL_ROLES, 'foreman'];

    public static function eligible(?User $user): bool
    {
        return $user !== null
            && $user->account_status === 'active'
            && in_array($user->access_role, self::ROLES, true)
            && (! $user->employee_id || $user->employee?->employment_status === 'active');
    }

    public static function authenticated(Request $request): bool
    {
        return self::eligible($request->user())
            && ((EmailPasswordAuthService::hasStrongAuthentication($request, $request->user())
                    && ! WorkerDeviceSession::isDeviceOnly($request))
                || $request->session()->get(PersonalAppAccessService::LEGACY_SESSION) === true);
    }

    /** A return link only: this never identifies a person to the gate or punches in. */
    public static function gateUrl(?Employee $employee): ?string
    {
        if ($employee === null || $employee->employment_status !== 'active') {
            return null;
        }

        $site = $employee->site;

        return $site && $site->status === 'active' ? route('gate.show', ['site' => $site->id]) : null;
    }
}
