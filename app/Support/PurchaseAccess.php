<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Super administrators have built-in access; other internal managers need explicit grants. */
final class PurchaseAccess
{
    public const ELIGIBLE_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];

    public static function eligible(?User $user): bool
    {
        return $user && $user->account_status === 'active'
            && in_array($user->access_role, self::ELIGIBLE_ROLES, true);
    }

    public static function canRequest(?User $user): bool
    {
        return self::eligible($user)
            && ($user->access_role === 'super_admin' || (bool) $user->purchase_request_enabled);
    }

    public static function canBuy(?User $user, ?Request $request = null): bool
    {
        if (! self::hasBuyerPermission($user)) {
            return false;
        }
        $request ??= app()->bound('request') ? request() : null;

        // Queue jobs recheck live grants; HTTP sessions must prove a password or Google login.
        return ! $request || ! $request->hasSession()
            || (! WorkerDeviceSession::isDeviceOnly($request)
                && EmailPasswordAuthService::hasStrongAuthentication($request, $user));
    }

    public static function hasBuyerPermission(?User $user): bool
    {
        return self::eligible($user)
            && ($user->access_role === 'super_admin' || (bool) $user->purchase_buy_enabled);
    }

    public static function canUseSite(?User $user, Site $site): bool
    {
        if (! self::eligible($user)) {
            return false;
        }
        if ($user->access_role === 'super_admin') {
            return true;
        }
        $companyId = (int) ($user->allowed_company_id ?: $user->employee?->company_id);
        if ($user->access_role === 'vendor_admin' && (! $companyId || $companyId !== (int) $site->company_id)) {
            return false;
        }

        return match ($user->access_scope) {
            'all_sites' => true,
            'company' => $companyId > 0 && $companyId === (int) $site->company_id,
            'site', 'team', 'self' => (int) ($user->allowed_site_id ?: $user->employee?->site_id) === $site->id
                && (! $companyId || $companyId === (int) $site->company_id),
            default => false,
        };
    }

    /** @return Collection<int, Site> */
    public static function sites(?User $user, bool $activeOnly = false): Collection
    {
        if (! self::eligible($user)) {
            return collect();
        }

        return Site::query()->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')->get()->filter(fn (Site $site): bool => self::canUseSite($user, $site))->values();
    }

    public static function assertBuyer(?User $user = null): User
    {
        $user ??= auth()->user();
        abort_unless(self::canBuy($user), 403, '구매 담당 권한과 ERP 로그인이 필요합니다.');

        return $user;
    }

    public static function assertSite(?User $user, int $id): Site
    {
        $site = Site::find($id);
        abort_unless($site && self::canUseSite($user, $site), 403, '이 현장에 접근할 권한이 없습니다.');

        return $site;
    }
}
