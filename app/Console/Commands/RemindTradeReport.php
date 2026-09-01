<?php

namespace App\Console\Commands;

use App\Services\Ops\TradeReportReminderService;
use Illuminate\Console\Command;

class RemindTradeReport extends Command
{
    protected $signature = 'ops:remind-trade-report';

    protected $description = '오늘 일했는데 보고가 없는 공종의 반장에게 묻고, 마감이 지나면 소장에게 올린다';

    public function handle(TradeReportReminderService $service): int
    {
        $r = $service->run();

        $this->info("미제출 공종 {$r['checked']}개, 알림 {$r['sent']}건, 소장 보고 {$r['escalated']}건");

        return self::SUCCESS;
    }
}
