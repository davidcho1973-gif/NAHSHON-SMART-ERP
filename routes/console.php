<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 자정 직후 하이브리드 출퇴근 세션 마감(마지막 이탈=퇴근, 미이탈=미마감).
Schedule::command('attendance:finalize-sessions')->dailyAt('00:05');
