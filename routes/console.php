<?php

use App\Models\SystemHeartbeat;
use App\Support\Org;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 스케줄러가 살아 있는지 남기는 맥박.
//
// 이 파일의 나머지 작업은 전부 "스케줄러가 돈다"는 전제 위에 있다. 그런데 그 전제가
// 깨져도 앱은 멀쩡해 보인다 — 출근은 찍히고 화면도 정상이고, 다만 퇴근이 안 찍히고
// 문서가 "분석 중"에 머물고 경비가 안 잡힐 뿐이다. 무엇이 안 도는지 알아채는 데
// 며칠이 걸린다. 그래서 주기적으로 시각 하나를 남기고, /build-version 과 알림 센터가
// 그 값을 읽어 "스케줄러가 돌고 있는가"를 한 줄로 답한다.
//
// 주기를 10분으로 잡은 것은 요금 때문이다. 데이터베이스가 서버리스라 5분 놀면 잠드는데,
// 매분 맥박을 찍으면 잠들 틈이 없어진다. 아래 docs:reap-stuck 이 이미 10분마다
// 데이터베이스를 건드리므로, 같은 리듬에 얹으면 깨어 있는 시간이 늘지 않는다.
//
// 캐시가 아니라 표에 남기는 이유: 캐시를 파일로 돌렸다(그래야 schedule:run 이 매분
// 데이터베이스를 깨우지 않는다). 파일 캐시는 컨테이너마다 따로라 스케줄러가 쓴 값을
// 웹 화면이 읽지 못한다.
Schedule::call(function (): void {
    SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
})->everyTenMinutes()->name('scheduler-heartbeat');

// 아래 시각은 전부 config/org.php 에서 온다. 회사마다 일과가 다르다.
//
// 데이터베이스(org_settings)가 아니라 설정 파일에서 읽는 이유 — 이 파일은 매분
// 평가된다. 여기서 표를 보면 schedule:run 이 매분 데이터베이스를 깨워, 서버리스
// 요금이 그대로 늘어난다. 설정 파일은 캐시되므로 그런 일이 없다.

// 자동 출퇴근 마감 — 퇴근 기록(clock_out)을 실제로 만드는 곳이다. 이게 안 돌면
// 출근만 남고 퇴근이 없어 근무시간이 0 이 되고 급여도 0 이 된다.
//
// 저녁 20:00 — 그날 일이 끝난 사람을 그날 안에 마감한다. 하루 지나서 기록이 나타나면
//              작업자도 반장도 그날 안에 확인할 수 없다. 아직 현장에 있는 사람(최근 30분
//              안에 재실이 확인된 사람)은 건너뛴다 — 저녁 마감이 연장 근무를 자르면 안 된다.
Schedule::command('attendance:finalize-sessions --today --grace='.Org::int('attendance.evening_grace_minutes', 30))
    ->dailyAt(Org::time('attendance.evening_finalize_at', '20:00'));

// 자정 00:05 — 안전망. 저녁에 건너뛴 사람과 늦게까지 남은 사람을 어제 날짜로 정리한다.
Schedule::command('attendance:finalize-sessions')->dailyAt(Org::time('attendance.safety_net_at', '00:05'));

// 간접고용(협력사) 퇴근 자동 마감 — 현장 16:00 기준(직접고용은 제외).
Schedule::command('attendance:auto-clockout')
    ->dailyAt(sprintf('%02d:05', Org::int('attendance.indirect_cutoff_hour', 16)));

// 만료 임박 문서(COI·면허·인허가·비자) 알림 — 매일 아침 업무 시작 전.
Schedule::command('docs:alert-expiring')->dailyAt(Org::time('schedule.docs_expiry_alert_at', '07:00'));

// 아침 출근 알림 — 사람마다 평소 출근 시각(최근 2주 중간값)이 지났는데 기록이 없으면
// 푸시로 묻는다. 웹 앱은 주머니 속에서 위치를 못 보내므로(OS 제한), 누르는 순간
// 앱이 열리며 찍히게 하는 것이 현실적인 자동이다. 10분마다 돌지만 아침 시간대
// 밖에서는 아무것도 하지 않고, 하루 2번을 넘지 않는다.
Schedule::command('attendance:remind-clockin')->everyTenMinutes();

// 퇴근 알림 — "아직 퇴근이 안 찍혔습니다".
//
// 출근보다 이쪽이 돈에 더 가깝다. 시급 직영은 자동 마감을 하지 않으므로(임금 왜곡
// 방지) 퇴근을 안 찍으면 미마감으로 남고, 급여 마감날 기억으로 채워진다. 같은 규칙
// (기록에서 배운 시각 · 하루 2번 · 시간대 밖에서는 아무것도 안 함)으로 그날 저녁에 묻는다.
Schedule::command('attendance:remind-clockout')->everyTenMinutes();

// 현장 상황실 하루 요약 — 일과 종료 무렵.
Schedule::command('ops:digest')->dailyAt(Org::time('schedule.ops_digest_at', '18:00'));

// 원청 정기 보고 — 아침 작업계획서, 저녁 마감보고서.
//
// 사람이 제출한 것만 나간다(미제출이면 조용히 보류 — 그건 실패가 아니라 아직 안 쓴 것이다).
// 실패하면 명령이 FAILURE 를 반환하고 알림 센터에 올린다. 이게 없던 동안에는 발송이
// 실패해도 로그 파일에만 남아서, 원청이 사흘째 못 받아도 화면은 정상으로 보였다.
Schedule::command('reports:send-daily plan')
    ->dailyAt(Org::time('schedule.daily_plan_send_at', '08:30'))
    ->onFailure(fn () => Log::error('일일 작업계획서 자동 발송 실패 — 알림 센터를 확인하세요.'));

Schedule::command('reports:send-daily closing')
    ->dailyAt(Org::time('schedule.daily_report_send_at', '18:30'))
    ->onFailure(fn () => Log::error('일일 마감보고서 자동 발송 실패 — 알림 센터를 확인하세요.'));

// 아침 브리핑 — "오늘 가장 위험한 3가지"를 영향도 순으로. 위험이 없으면 조용하다.
Schedule::command('ops:morning-brief')->dailyAt(Org::time('schedule.morning_brief_at', '06:30'));

// 장비 임대료·숙소 월세 → 월별 경비 자동 계상(pending, 사람이 승인).
// 매일 새벽에 돌려도 멱등이라 안전하고, 월중에 등록된 장비도 그 달치가 잡힌다.
Schedule::command('finance:accrue-rentals')->dailyAt(Org::time('schedule.rental_accrual_at', '05:30'));

// 주간 리듬(LPS) — 월요일 아침, 지난주 약속 이행률(PPC) 집계 + 이번 주 약속 제안 + 방 요약.
Schedule::command('wbs:weekly-plan')->weeklyOn(1, Org::time('schedule.weekly_plan_at', '05:00'));

// 공정 CPM 안전망 — 평소에는 편집 순간마다 재계산되므로 바꿀 게 없어야 정상.
// 다른 경로로 어긋난 여유·주공정·예상 준공을 하루 안에 스스로 바로잡는다.
Schedule::command('wbs:recompute-cpm')->dailyAt(Org::time('schedule.cpm_recompute_at', '04:30'));

// "AI 분석 중"에서 멈춘 문서 되살리기 — 작업 프로세스가 죽으면 상태를 되돌릴 사람이 없다.
// 한 번은 자동 재시도, 그래도 멈추면 실패로 표시해 사용자가 알 수 있게 한다.
Schedule::command('docs:reap-stuck')->everyTenMinutes();

// 지식 창고 안전망 — 새 문서는 분석 직후 자동 수확되지만, 수확이 실패했거나
// 이 기능 이전에 분석된 문서를 매시간 쓸어 담는다. 최신 문서는 isFresh 로
// 건너뛰므로 평소에는 문서당 조회 한 번으로 끝난다 — 임베딩 호출이 헛돌지 않는다.
// (매시간인 이유: 배포 후 첫 축적을 사람이 콘솔 없이 한 시간 안에 받게 하려고.)
Schedule::command('erp:harvest-knowledge')->hourly();
