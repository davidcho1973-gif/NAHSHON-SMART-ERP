<?php

return [
    // Optional additional zones for existing/non-US sites. The US site dropdown
    // and application timezone are always included by SiteSchedule.
    'timezones' => env('SITE_SCHEDULE_TIMEZONES', ''),
];
