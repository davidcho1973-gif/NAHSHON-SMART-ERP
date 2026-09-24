<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Services\Alerts\UnifiedAlertService;

/**
 * 「이 사람은 시급 대상인데 임금률이 없다」 는 말을 하는 단 한 곳.
 *
 * 임금 프로필은 0원으로 태어난다. 아무도 채우지 않으면 급여를 돌리는 날에야 $0
 * 명세서로 드러나고, 그때는 이미 2주치가 지나 있다.
 *
 * 말할 순간이 둘이다 — 자사 직영으로 <b>등록될 때</b>, 그리고 인사가 «미확인» 이던
 * 사람을 <b>자사 직영으로 확인할 때</b>. 문장을 양쪽에 적어 두면 한쪽만 고쳐지고,
 * 고쳐지지 않은 쪽은 아무도 안 읽는다. 그래서 한 곳에 둔다.
 *
 * 임금률을 작업자에게 묻지는 않는다. 얼마를 줄지는 회사가 정하는 것이고, 본인이
 * 적어 넣게 하면 그 숫자가 그대로 급여가 된다.
 */
final class PayrollSetupAlert
{
    public static function emitIfMissing(Employee $employee): void
    {
        try {
            if (! $employee->isHourly()) {
                return;
            }

            if ((float) ($employee->payrollProfile?->base_rate ?? 0) > 0) {
                return;
            }

            app(UnifiedAlertService::class)->emit("payroll-setup-missing:{$employee->id}", [
                'company_id' => $employee->company_id,
                'site_id' => $employee->site_id,
                'employee_id' => $employee->id,
                'source_module' => 'PAYROLL',
                'source_type' => Employee::class,
                'source_id' => (string) $employee->id,
                'event_type' => 'payroll_setup_missing',
                'severity' => 'warning',
                'title' => "임금률 미설정: {$employee->name}",
                'content' => sprintf(
                    '%s 님이 자사 직영(시급)으로 확인됐습니다. 임금률이 없으면 $0 명세서가 발행됩니다 — 급여 마감 전에 임금 프로필에서 시급을 입력하세요.%s',
                    $employee->name,
                    $employee->positionLabel() ? ' (직책: '.$employee->positionLabel().')' : '',
                ),
                'action_url' => '/admin/pay-profiles',
            ]);
        } catch (\Throwable $e) {
            report($e); // 알림 실패가 등록·확인 자체를 막으면 안 된다.
        }
    }
}
