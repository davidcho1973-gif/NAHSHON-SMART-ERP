<?php

namespace App\Console\Commands;

use App\Services\Attendance\ClockOutReminderService;
use Illuminate\Console\Command;

class RemindClockOut extends Command
{
    protected $signature = 'attendance:remind-clockout';

    protected $description = '평소 퇴근 시각이 지났는데 퇴근 기록이 없는 사람에게 푸시로 퇴근 확인을 묻는다';

    public function handle(ClockOutReminderService $service): int
    {
        $result = $service->run();

        $this->info("확인 {$result['checked']}명, 알림 {$result['sent']}건");

        return self::SUCCESS;
    }
}
