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

// 장비 임대료·숙소 월세 → 월별 경비 자동 계상(pending, 사람이 승인).
// 매일 새벽에 돌려도 멱등이라 안전하고, 월중에 등록된 장비도 그 달치가 잡힌다.
Schedule::command('finance:accrue-rentals')->dailyAt('05:30');

// "AI 분석 중"에서 멈춘 문서 되살리기 — 작업 프로세스가 죽으면 상태를 되돌릴 사람이 없다.
// 한 번은 자동 재시도, 그래도 멈추면 실패로 표시해 사용자가 알 수 있게 한다.
Schedule::command('docs:reap-stuck')->everyTenMinutes();
