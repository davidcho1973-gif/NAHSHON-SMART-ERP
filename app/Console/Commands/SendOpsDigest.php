<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 현장 상황실 하루 요약 — 저녁에 그날 판독·반영 결과를 방과 관리자에게 알린다.
 */
class SendOpsDigest extends Command
{
    protected $signature = 'ops:digest {date? : YYYY-MM-DD, 기본=오늘}';

    protected $description = '현장 상황실 하루 요약 발송';

    public function handle(OpsDigestService $service): int
    {
        $date = $this->argument('date') ? Carbon::parse($this->argument('date')) : Carbon::today();
        $r = $service->dispatchDigest($date);

        $this->info(sprintf('[%s] 현장 %d곳 · 방 게시 %d · 알림 %d건', $date->toDateString(), $r['sites'], $r['posted'], $r['notified']));

        return self::SUCCESS;
    }
}
