<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Shared site boundary for legacy HTTP adapters and their backing queries. */
final class OperationalAccess
{
    public static function siteIds(User $user): array
    {
        return Site::query()->get()->filter(fn (Site $s) => AiInformationAccess::canUseSite($user, $s))->modelKeys();
    }

    public static function scope($query, ?User $user = null)
    {
        $user ??= auth()->user();
        // Domain services also run as trusted console jobs, without a web actor.
        if (! $user || AccessPolicy::canManageSystem($user)) {
            return $query;
        }

        return $query->whereIn('site_id', self::siteIds($user));
    }

    public static function assertSite(?int $siteId, ?User $user = null): void
    {
        $user ??= auth()->user();
        abort_unless($user, 403);
        if (AccessPolicy::canManageSystem($user)) {
            return;
        }
        $site = $siteId ? Site::find($siteId) : null;
        abort_unless($site && AiInformationAccess::canUseSite($user, $site), 403, '이 현장에 접근할 권한이 없습니다.');
    }

    public static function assertRecord(?Model $record): void
    {
        abort_unless($record, 404);
        self::assertSite($record->site_id);
    }

    public static function assertManage(): void
    {
        abort_unless(AccessPolicy::canManageSite(auth()->user()), 403, '현장 관리 권한이 필요합니다.');
    }
}
