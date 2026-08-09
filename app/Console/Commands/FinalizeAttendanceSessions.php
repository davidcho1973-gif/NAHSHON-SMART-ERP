<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceGeoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 자동 출퇴근의 하루 마감.
 *
 * 자동 출근은 현장에 들어오는 순간 바로 찍히지만, 퇴근 기록(clock_out)은 이 작업이 쓴다.
 * 이게 안 돌면 출근만 남고 퇴근이 없어 근무시간이 0 이 되고, 급여도 0 이 된다.
 *
 * 하루에 두 번 돈다.
 *   저녁 — 그날 일이 끝난 사람을 그날 안에 마감한다. 아직 일하고 있는 사람은 건너뛴다.
 *   자정 — 저녁에 건너뛴 사람과 늦게까지 남은 사람을 정리하는 안전망.
 */
class FinalizeAttendanceSessions extends Command
{
    protected $signature = 'attendance:finalize-sessions
        {date? : YYYY-MM-DD, 기본=어제}
        {--today : 어제 대신 오늘을 마감한다(저녁 마감용)}
        {--grace=0 : 최근 이 시간(분) 안에 현장에 있던 사람은 아직 근무중으로 보고 건너뛴다}';

    protected $description = '자동 출퇴근 세션을 마감해 퇴근 기록을 만든다';

    public function handle(AttendanceGeoService $service): int
    {
        $date = $this->argument('date')
            ? Carbon::parse((string) $this->argument('date'))
            : ($this->option('today') ? Carbon::today() : Carbon::yesterday());

        $grace = max(0, (int) $this->option('grace'));

        $r = $service->finalize($date, $grace);

        $this->info(sprintf(
            '[%s] 마감 %d건 · 확인필요 %d건 · 근무중이라 건너뜀 %d건',
            $date->toDateString(),
            $r['finalized'],
            $r['needsReview'],
            $r['skipped'],
        ));

        return self::SUCCESS;
    }
}
