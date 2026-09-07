<?php

namespace App\Console\Commands;

use App\Services\Kakao\WorkReminderService;
use Illuminate\Console\Command;

class KakaoWorkReminders extends Command
{
    protected $signature = 'kakao:remind-work {--dry-run : Check due counts without sending or writing delivery records}';

    protected $description = 'Send opted-in work reminders through approved Kakao Alimtalk templates';

    public function handle(WorkReminderService $service): int
    {
        $this->line(json_encode($service->run((bool) $this->option('dry-run')), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
