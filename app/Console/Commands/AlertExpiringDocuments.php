<?php

namespace App\Console\Commands;

use App\Services\DocumentExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 만료 임박 문서 알림 — 매일 아침 D-60/30/14/7/1 및 당일 만료 문서를 관리자에게 알린다.
 */
class AlertExpiringDocuments extends Command
{
    protected $signature = 'docs:alert-expiring {date? : YYYY-MM-DD, 기본=오늘}';

    protected $description = '만료 임박 문서를 찾아 관리자에게 알림 발송';

    public function handle(DocumentExpiryService $service): int
    {
        $date = $this->argument('date') ? Carbon::parse($this->argument('date')) : Carbon::today();
        $r = $service->dispatchAlerts($date);

        $this->info(sprintf('[%s] 대상 문서 %d건, 알림 %d건 발송', $date->toDateString(), $r['documents'], $r['sent']));

        return self::SUCCESS;
    }
}
