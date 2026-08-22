<?php

namespace App\Console\Commands;

use App\Services\Attendance\ClockInReminderService;
use Illuminate\Console\Command;

class RemindClockIn extends Command
{
    protected $signature = 'attendance:remind-clockin';

    protected $description = '평소 출근 시각이 지났는데 기록이 없는 사람에게 푸시로 출근 확인을 묻는다';

    public function handle(ClockInReminderService $service): int
    {
        $result = $service->run();

        $this->info("확인 {$result['checked']}명, 알림 {$result['sent']}건");

        return self::SUCCESS;
    }
}
