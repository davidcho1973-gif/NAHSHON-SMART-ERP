<?php

namespace App\Support;

use App\Models\Site;

/** Site-local scheduling without a database query on every scheduler tick. */
final class SiteSchedule
{
    public static function timezones(): array
    {
        // Every selectable site zone must receive the same local-day jobs. Keeping
        // the catalog here avoids both missed new regions and a query each minute.
        return array_values(array_unique(array_filter(array_map('trim', array_merge(
            array_keys(Site::US_TIMEZONE_OPTIONS),
            explode(',', (string) config('site-schedule.timezones')),
            [(string) config('app.timezone')]
        )))));
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
