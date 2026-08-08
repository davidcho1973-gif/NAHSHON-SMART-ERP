<?php

namespace App\Console\Commands;

use App\Services\Attendance\AutoClockOutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 간접고용(협력사) 퇴근 자동 마감 — 현장 기준 16:00.
 * 직접고용은 임금 왜곡을 막기 위해 자동 마감하지 않고 미마감으로 남긴다.
 */
class AutoClockOutIndirect extends Command
{
    protected $signature = 'attendance:auto-clockout {date? : YYYY-MM-DD, 기본=오늘}';

    protected $description = '퇴근 미기록 간접고용 인원을 16:00 으로 자동 마감';

    public function handle(AutoClockOutService $service): int
    {
        $date = $this->argument('date') ? Carbon::parse($this->argument('date')) : null;
        $r = $service->run($date);

        $this->info(sprintf(
            '[%s] 간접고용 자동 퇴근 %d건 · 직접고용 미마감 %d건(관리자 확인 필요)',
            $r['date'], $r['closed'], $r['pendingDirect'],
        ));

        return self::SUCCESS;
    }
}
