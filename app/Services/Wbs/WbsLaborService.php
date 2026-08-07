<?php

namespace App\Services\Wbs;

use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\WbsItem;
use Illuminate\Support\Collection;

/**
 * 공정 ↔ 인원 ↔ 급여를 잇는 지점.
 *
 * 사슬:
 *   WBS SubTask ← 안전카드(work_date) ← 서명(employee_id) → 타임시트(employee_id, 같은 날)
 *
 * 안전카드가 "그날 그 작업에 누가 투입됐는가"를 알고, 타임시트가 "그 사람이 그날 몇 시간
 * 일했는가"를 안다. 둘의 조인 키가 (employee_id, 날짜)이므로 공정별 실투입 공수가 나온다.
 *
 * 계획(crew_size × 공기 × 8h)과 실적(타임시트 실근무)을 대비하면 공정별 생산성이 보인다.
 */
class WbsLaborService
{
    /**
     * 서명란에 직원을 배정한다. 이름은 서명 당시 기록으로 복사해 둔다(법적 기록 보존).
     *
     * @return array<string, mixed>
     */
    public function assignEmployee(int $signatureId, ?int $employeeId): array
    {
        $sig = SafetyWorkSignature::query()->with('workItem')->find($signatureId);
        if (! $sig) {
            return ['success' => false, 'error' => '서명란을 찾을 수 없습니다.'];
        }

        // 이미 서명된 칸은 사람을 바꿀 수 없다 — 서명은 법적 기록이다.
        if ($sig->signed) {
            return ['success' => false, 'error' => '이미 서명이 완료된 칸은 배정을 변경할 수 없습니다.'];
        }

        if ($employeeId === null) {
            $sig->employee_id = null;
            $sig->name = '';
            $sig->save();

            return ['success' => true, 'signature_id' => $sig->id, 'employee_id' => null];
        }

        $employee = Employee::query()->find($employeeId);
        if (! $employee) {
            return ['success' => false, 'error' => "직원을 찾을 수 없습니다: {$employeeId}"];
        }

        $sig->employee_id = $employee->id;
        $sig->name = $this->displayName($employee);
        $sig->save();

        return [
            'success' => true,
            'signature_id' => $sig->id,
            'employee_id' => $employee->id,
            'name' => $sig->name,
        ];
    }

    /**
     * 공정 하나의 계획 대비 실투입.
     *
     * @return array<string, mixed>
     */
    public function laborFor(string $wbsCode): array
    {
        $item = WbsItem::query()->where('wbs_code', $wbsCode)->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }

        $cards = SafetyWorkItem::query()
            ->where('wbs_code', $wbsCode)
            ->with(['signatures.employee'])
            ->orderBy('work_date')
            ->get();

        $days = $cards->map(fn (SafetyWorkItem $card) => $this->dayLabor($card))->all();

        $actualHours = array_sum(array_column($days, 'hours'));
        $plannedHours = (float) $item->manhours;

        return [
            'success' => true,
            'wbs_id' => $wbsCode,
            'name' => $item->name,
            'plannedCrew' => $item->crew_size !== null ? (float) $item->crew_size : null,
            'plannedHours' => $plannedHours,
            'actualHours' => round($actualHours, 1),
            // 계획 대비 실적. 100% 초과면 예상보다 인시가 더 들어갔다는 뜻.
            'consumedPct' => $plannedHours > 0 ? (int) round($actualHours / $plannedHours * 100) : null,
            'days' => $days,
        ];
    }

    /**
     * 하루치: 그날 서명한 사람들과 그들의 실근무 시간.
     *
     * @return array<string, mixed>
     */
    private function dayLabor(SafetyWorkItem $card): array
    {
        $date = $card->work_date?->toDateString();

        $signed = $card->signatures->filter(fn (SafetyWorkSignature $s) => $s->signed && $s->employee_id !== null);
        $timesheets = $this->timesheetsFor($signed->pluck('employee_id')->all(), $date);

        $people = $signed->map(function (SafetyWorkSignature $s) use ($timesheets): array {
            $ts = $timesheets->get($s->employee_id);

            return [
                'employeeId' => $s->employee_id,
                'name' => $s->name,
                'role' => $s->role,
                // 타임시트는 분 단위로 기록된다(payable_minutes = 정규 + 초과).
                // 출퇴근 기록이 아직 없으면 null — 0 으로 뭉개면 "일 안 했다"로 오독된다.
                'hours' => $ts ? round((int) $ts->payable_minutes / 60, 1) : null,
                'timesheetStatus' => $ts?->status,
            ];
        })->values()->all();

        return [
            'date' => $date,
            'workCode' => $card->work_code,
            'tbmCleared' => $card->isTbmCleared(),
            'signedCount' => $signed->count(),
            'slotCount' => $card->signatures->count(),
            'people' => $people,
            'hours' => round(array_sum(array_filter(array_column($people, 'hours'))), 1),
        ];
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, PayrollTimesheet>
     */
    private function timesheetsFor(array $employeeIds, ?string $date): Collection
    {
        if ($employeeIds === [] || $date === null) {
            return collect();
        }

        return PayrollTimesheet::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('work_date', $date)
            ->get()
            ->keyBy('employee_id');
    }

    private function displayName(Employee $e): string
    {
        foreach ([$e->name, trim((string) $e->last_name . ' ' . (string) $e->first_name)] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return (string) ($e->employee_number ?? $e->badge_number ?? "EMP-{$e->id}");
    }
}
