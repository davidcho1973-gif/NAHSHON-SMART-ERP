<?php

namespace App\Console\Commands;

use App\Services\Finance\RentalExpenseConnector;
use Illuminate\Console\Command;

/**
 * 장비 임대료·숙소 월세를 이 달 경비(원장)에 자동 계상한다.
 *
 * 대장에 저장만 되고 아무 합산에도 안 잡히던 고정비를 매월 pending 경비로
 * 만들어 두면, 재무 담당은 다시 입력하는 대신 승인만 하면 된다.
 */
class AccrueRentalExpenses extends Command
{
    protected $signature = 'finance:accrue-rentals {--month= : 대상 월 (YYYY-MM, 기본 이번 달)}';

    protected $description = '장비 임대료·숙소 월세를 월별 경비로 자동 계상 (멱등)';

    public function handle(RentalExpenseConnector $connector): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');
        $result = $connector->accrueMonth($month);

        $this->info("{$month}: 생성 {$result['created']} · 갱신 {$result['updated']} · 유지 {$result['skipped']}");

        return self::SUCCESS;
    }
}
