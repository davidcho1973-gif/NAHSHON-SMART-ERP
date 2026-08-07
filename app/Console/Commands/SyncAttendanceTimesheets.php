<?php

namespace App\Console\Commands;

use App\Services\Payroll\AttendanceTimesheetSync;
use Illuminate\Console\Command;

class SyncAttendanceTimesheets extends Command
{
    protected $signature = 'payroll:sync-timesheets
        {--from= : 시작일 (YYYY-MM-DD, 미지정 시 전체)}
        {--to= : 종료일 (YYYY-MM-DD, 미지정 시 전체)}';

    protected $description = 'attendance_logs(출퇴근)를 payroll_timesheets(급여 근무시간)로 백필 동기화한다. 신규 기록은 자동 동기화되며, 이 명령은 기존 데이터용이다.';

    public function handle(AttendanceTimesheetSync $sync): int
    {
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;

        $this->info('출퇴근 → 급여 타임시트 동기화 중...'.($from || $to ? " ({$from} ~ {$to})" : ' (전체)'));

        $count = $sync->backfill($from, $to);

        $this->info("완료: {$count}건의 (직원·일자) 타임시트를 동기화했습니다.");

        return self::SUCCESS;
    }
}
