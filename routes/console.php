<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 자정 직후 하이브리드 출퇴근 세션 마감(마지막 이탈=퇴근, 미이탈=미마감).
Schedule::command('attendance:finalize-sessions')->dailyAt('00:05');

// 간접고용(협력사) 퇴근 자동 마감 — 현장 16:00 기준(직접고용은 제외).
Schedule::command('attendance:auto-clockout')->dailyAt('16:05');

// 만료 임박 문서(COI·면허·인허가·비자) 알림 — 매일 아침 업무 시작 전.
Schedule::command('docs:alert-expiring')->dailyAt('07:00');

// 현장 상황실 하루 요약 — 일과 종료 무렵.
Schedule::command('ops:digest')->dailyAt('18:00');
