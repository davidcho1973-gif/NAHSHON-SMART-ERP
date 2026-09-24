<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\Payroll\AttendanceTimesheetSync;
use App\Services\Payroll\PayrollSetupAlert;

/**
 * 고용형태가 바뀌면 그 사람의 급여 시트를 다시 계산한다.
 *
 * `payroll_timesheets` 는 두 가지에서 나온다 — 출퇴근 기록과 그 사람의 고용형태.
 * 그런데 다시 계산하는 손잡이가 출퇴근 기록 쪽에만 달려 있었다(AttendanceLog 의
 * saved/deleted/restored). 고용형태는 <b>그 표가 있느냐 없느냐</b>를 정하는 값인데도
 * 그쪽이 바뀔 때는 아무 일도 일어나지 않았다.
 *
 * 그래서 이런 구멍이 생긴다: 현장 QR 로 등록한 사람은 소속이 확인되기 전까지 급여
 * 시트를 만들지 않는데(누가 임금을 주는지 모르는 상태), 며칠 뒤 인사에서 «자사 직영»
 * 으로 확인해도 그 며칠은 급여에서 통째로 빠진 채 남는다. 사람은 일했고 출퇴근 기록도
 * 멀쩡히 있는데 임금만 없다 — 급여를 뽑는 날에야, 그것도 본인이 말해야 드러난다.
 *
 * 파생된 표는 자기 입력 <b>전부</b>를 따라야 한다. 그 규칙을 여기 한 곳에 둔다.
 */
class EmployeeTimesheetPolicyObserver
{
    public function updated(Employee $employee): void
    {
        // 고용형태만 본다. 이름·전화가 바뀔 때마다 그 사람의 한 해치 출퇴근을
        // 다시 계산하면 저장 한 번이 느려지고, 얻는 것은 없다.
        if (! $employee->wasChanged('employment_type')) {
            return;
        }

        try {
            app(AttendanceTimesheetSync::class)->resyncEmployee($employee->id);

            // 방금 시급 대상이 된 사람인데 임금률이 없으면 지금 말한다. 여기서 말하지
            // 않으면 «미확인» 이던 사람이 자사 직영으로 확인된 순간부터 $0 으로 쌓인다.
            PayrollSetupAlert::emitIfMissing($employee->fresh() ?? $employee);
        } catch (\Throwable $exception) {
            // 급여 재계산이 실패해도 직원 정보 저장은 막지 않는다 — 기록은 남아 있고
            // `payroll:sync-attendance` 로 언제든 다시 돌릴 수 있다.
            report($exception);
        }
    }
}
