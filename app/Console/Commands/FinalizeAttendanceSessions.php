<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceGeoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 자정 마감 — 그날 마지막 이탈을 퇴근으로 확정. 아직 현장 안이면 미마감(관리자 확인).
 * 매일 00:05 에 "어제(막 끝난 날)" 세션을 마감한다.
 */
class FinalizeAttendanceSessions extends Command
{
    protected $signature = 'attendance:finalize-sessions {date? : YYYY-MM-DD, 기본=어제}';

    protected $description = '하이브리드 출퇴근 세션을 마감(마지막 이탈=퇴근, 미이탈=미마감)';

    public function handle(AttendanceGeoService $service): int
    {
        $date = $this->argument('date') ? Carbon::parse($this->argument('date')) : Carbon::yesterday();
        $r = $service->finalize($date);
        $this->info(sprintf('[%s] 마감 %d건, 미마감(확인필요) %d건', $date->toDateString(), $r['finalized'], $r['needsReview']));

        return self::SUCCESS;
    }
}
