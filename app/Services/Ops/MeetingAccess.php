<?php

namespace App\Services\Ops;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class MeetingAccess
{
    public static function allowed(?User $user): bool
    {
        return $user && $user->account_status === 'active'
            && in_array($user->access_role, ['super_admin', 'admin', 'site_manager'], true);
    }

    public static function sites(User $user): Builder
    {
        $q = Site::query();
        if (! self::allowed($user)) {
            return $q->whereRaw('1=0');
        }
        if ($user->access_role === 'super_admin') {
            return $q;
        }
        $q->whereIn('company_id', $user->accessibleCompanies()->pluck('id'));

        return match ($user->access_scope) {
            'all_sites' => $q,
            'company' => $q->where('company_id', $user->allowed_company_id ?: 0),
            'site' => $q->whereKey($user->allowed_site_id ?: 0),
            default => $q->whereRaw('1=0'),
        };
    }

    public static function check(?User $user, int $siteId): void
    {
        abort_unless(self::allowed($user) && self::sites($user)->whereKey($siteId)->exists(), 403, '이 현장의 회의를 처리할 권한이 없습니다.');
    }
}
