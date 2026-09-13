<?php

namespace App\Support;

use App\Models\Site;

/** Site-local scheduling without a database query on every scheduler tick. */
final class SiteSchedule
{
    public static function timezones(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            explode(',', (string) config('site-schedule.timezones')),
            [(string) config('app.timezone')]
        ))));
    }

    public static function matches(?Site $site, ?string $timezone): bool
    {
        return ! $timezone || ($site?->timezone ?: config('app.timezone')) === $timezone;
    }

    public static function sites(?string $timezone): array
    {
        return Site::where('status', 'active')->get()
            ->filter(fn (Site $site) => self::matches($site, $timezone))->modelKeys();
    }
}
