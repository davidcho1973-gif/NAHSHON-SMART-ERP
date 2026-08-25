<?php

namespace App\Services\Admin;

use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\Payslip;
use App\Models\Site;
use App\Models\User;

/**
 * 임금 프로필 — Filament EmployeePayrollProfileResource 를 SPA 로 옮긴 것.
 *
 * 급여 사슬의 마지막 입력점이다. 시간은 출퇴근에서 자동으로 오지만 "얼마"는 사람이
 * 여기서 정한다. 단가가 0 이면 아무리 일해도 급여가 0 이므로, 목록에서 미입력을
 * 눈에 띄게 보여 준다 — 급여 마감 날 발견하면 늦다.
 *
 * 직원 생성 시 프로필은 자동으로 만들어진다(EmployeePayrollProfileObserver). 그래서
 * 여기서 하는 일은 대부분 "만들기"가 아니라 "단가 채우기"다.
 */
class PayProfileService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'payroll', 'hr_manager'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'payroll'];

    public const PAY_TYPES = [
        'hourly' => '시급 (Hourly)',
        'salary' => '연봉 (Salary)',
        'daily' => '일급 (Daily)',
    ];

    public const DIVISIONS = [
        '관리자' => '관리자 (Manager)',
        '한국인' => '한국인 (Korean)',
        '외국인' => '외국인 (Local)',
    ];

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '임금 프로필을 볼 권한이 없습니다.'];
        }

        $rows = EmployeePayrollProfile::query()
            ->with(['employee:id,name,employee_number,employment_status', 'site:id,code,name'])
            ->get()
            ->sortBy(fn (EmployeePayrollProfile $p) => $p->employee?->name ?? '')
            ->map(fn (EmployeePayrollProfile $p): array => [
                'id' => $p->id,
                'employeeId' => $p->employee_id,
                'employee' => $p->employee?->name ?: '(직원 없음)',
                'employeeNumber' => $p->employee?->employee_number,
                'employmentStatus' => $p->employee?->employment_status,
                'payType' => $p->pay_type,
                'payTypeLabel' => self::PAY_TYPES[$p->pay_type] ?? (string) $p->pay_type,
                'baseRate' => $p->base_rate !== null ? (float) $p->base_rate : null,
                // 단가 0 은 "아직 안 정했다"는 뜻이다 — 급여가 0 으로 나가기 전에 보여야 한다.
                'rateMissing' => (float) $p->base_rate <= 0,
                'overtimeMultiplier' => $p->overtime_multiplier !== null ? (float) $p->overtime_multiplier : null,
                'currency' => $p->pay_currency,
                'perDiem' => $p->per_diem_rate !== null ? (float) $p->per_diem_rate : null,
                'fringeRate' => $p->fringe_rate !== null ? (float) $p->fringe_rate : null,
                'trade' => $p->trade,
                'division' => $p->worker_division,
                'isExempt' => (bool) $p->is_exempt,
                'isDispatched' => (bool) $p->is_dispatched,
                'visaType' => $p->visa_type,
                'site' => $p->site?->code,
                'siteId' => $p->site_id,
                'effectiveFrom' => $p->effective_from?->toDateString(),
            ])->values()->all();

        return [
            'success' => true,
            'profiles' => $rows,
            'canManage' => $this->canManage(),
            'missingRates' => count(array_filter($rows, fn (array $r) => $r['rateMissing'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '임금 프로필을 볼 권한이 없습니다.'];
        }

        $taken = EmployeePayrollProfile::query()->pluck('employee_id')->all();

        return [
            'success' => true,
            'payTypes' => $this->pairs(self::PAY_TYPES),
            'divisions' => $this->pairs(self::DIVISIONS),
            'currencies' => [['value' => 'USD', 'label' => 'USD'], ['value' => 'KRW', 'label' => 'KRW']],
            // 프로필이 없는 활성 직원만 — 한 직원에 프로필이 둘이면 어느 단가가 맞는지 알 수 없다.
            'employees' => Employee::query()
                ->where('employment_status', 'active')
                ->whereNotIn('id', $taken)
                ->orderBy('name')
                ->get(['id', 'name', 'employee_number'])
                ->map(fn (Employee $e): array => [
                    'value' => (string) $e->id,
                    'label' => $e->name.($e->employee_number ? " ({$e->employee_number})" : ''),
                ])->all(),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' · '.$s->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '임금 프로필을 수정할 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $profile = $id > 0 ? EmployeePayrollProfile::query()->find($id) : null;

        if ($id > 0 && ! $profile) {
            return ['success' => false, 'error' => '임금 프로필을 찾을 수 없습니다.'];
        }

        if (! $profile) {
            $employeeId = (int) ($input['employee_id'] ?? 0);
            $employee = Employee::query()->find($employeeId);
            if (! $employee) {
                return ['success' => false, 'error' => '직원을 선택해 주세요.'];
            }
            if (EmployeePayrollProfile::query()->where('employee_id', $employeeId)->exists()) {
                return ['success' => false, 'error' => '이 직원의 임금 프로필이 이미 있습니다. 기존 프로필을 수정해 주세요.'];
            }
            $profile = new EmployeePayrollProfile([
                'employee_id' => $employeeId,
                'company_id' => $employee->company_id,
            ]);
        }

        $payType = (string) ($input['pay_type'] ?? $profile->pay_type ?: 'hourly');
        if (! isset(self::PAY_TYPES[$payType])) {
            return ['success' => false, 'error' => '임금 형태가 올바르지 않습니다.'];
        }

        $rate = $this->number($input['base_rate'] ?? $profile->base_rate);
        if ($rate < 0) {
            return ['success' => false, 'error' => '기준 임금은 0 이상이어야 합니다.'];
        }

        $division = trim((string) ($input['worker_division'] ?? ''));
        if ($division !== '' && ! isset(self::DIVISIONS[$division])) {
            return ['success' => false, 'error' => '직군이 올바르지 않습니다.'];
        }

        $profile->fill([
            'site_id' => ($input['site_id'] ?? '') !== '' ? (int) $input['site_id'] : null,
            'pay_type' => $payType,
            'base_rate' => $rate,
            'overtime_multiplier' => $this->number($input['overtime_multiplier'] ?? $profile->overtime_multiplier ?: 1.5) ?: 1.5,
            'pay_currency' => in_array($input['pay_currency'] ?? '', ['USD', 'KRW'], true) ? $input['pay_currency'] : ($profile->pay_currency ?: 'USD'),
            'per_diem_rate' => $this->number($input['per_diem_rate'] ?? $profile->per_diem_rate),
            'fringe_rate' => $this->number($input['fringe_rate'] ?? $profile->fringe_rate) ?: 0,
            'trade' => $this->text($input['trade'] ?? null),
            'worker_division' => $division !== '' ? $division : null,
            'is_exempt' => (bool) ($input['is_exempt'] ?? false),
            'is_dispatched' => (bool) ($input['is_dispatched'] ?? false),
            'visa_type' => $this->text($input['visa_type'] ?? null),
            'effective_from' => $this->text($input['effective_from'] ?? null),
        ]);
        $profile->save();

        return ['success' => true, 'id' => $profile->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '임금 프로필을 삭제할 권한이 없습니다.'];
        }

        $profile = EmployeePayrollProfile::query()->find($id);
        if (! $profile) {
            return ['success' => false, 'error' => '임금 프로필을 찾을 수 없습니다.'];
        }

        // 이미 지급된 급여의 근거를 지우면 명세서를 다시 설명할 수 없다.
        if ($profile->employee_id && Payslip::query()->where('employee_id', $profile->employee_id)->exists()) {
            return ['success' => false, 'error' => '이 직원의 급여 명세가 있어 삭제할 수 없습니다. 단가를 0 으로 두거나 직원을 비활성화해 주세요.'];
        }

        $profile->delete();

        return ['success' => true];
    }

    /** @param array<string, string> $map */
    private function pairs(array $map): array
    {
        return array_map(fn ($k, $v) => ['value' => (string) $k, 'label' => $v], array_keys($map), array_values($map));
    }

    private function number(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function text(mixed $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
