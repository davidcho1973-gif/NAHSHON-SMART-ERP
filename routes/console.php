<?php

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

// 자동 출퇴근 마감 — 퇴근 기록(clock_out)을 실제로 만드는 곳이다. 이게 안 돌면
// 출근만 남고 퇴근이 없어 근무시간이 0 이 되고 급여도 0 이 된다.
//
// 저녁 20:00 — 그날 일이 끝난 사람을 그날 안에 마감한다. 하루 지나서 기록이 나타나면
//              작업자도 반장도 그날 안에 확인할 수 없다. 아직 현장에 있는 사람(최근 30분
//              안에 재실이 확인된 사람)은 건너뛴다 — 저녁 마감이 연장 근무를 자르면 안 된다.
Schedule::command('attendance:finalize-sessions --today --grace=30')->dailyAt('20:00');

// 자정 00:05 — 안전망. 저녁에 건너뛴 사람과 늦게까지 남은 사람을 어제 날짜로 정리한다.
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
