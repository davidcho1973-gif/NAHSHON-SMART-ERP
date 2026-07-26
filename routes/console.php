<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 자정 직후 하이브리드 출퇴근 세션 마감(마지막 이탈=퇴근, 미이탈=미마감).
Schedule::command('attendance:finalize-sessions')->dailyAt('00:05');

// 만료 임박 문서(COI·면허·인허가·비자) 알림 — 매일 아침 업무 시작 전.
Schedule::command('docs:alert-expiring')->dailyAt('07:00');
