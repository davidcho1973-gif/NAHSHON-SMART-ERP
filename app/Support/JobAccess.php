<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class JobAccess
{
    public static function managed(?User $user): bool
    {
        return $user !== null && filled($user->job_role);
    }

    public static function can(?User $user, string $module, string $action = 'view'): bool
    {
        if (! $user || $user->account_status !== 'active') {
            return false;
        }
        if ($user->access_role === 'super_admin') {
            return true;
        }

        return self::managed($user) && in_array($action, (array) ($user->job_permissions[$module] ?? []), true);
    }

    public static function defaults(string $job, array $duties = []): array
    {
        $permissions = config('job_access.jobs.'.$job.'.permissions', []);
        foreach ($duties as $duty) {
            foreach (config('job_access.duties.'.$duty.'.permissions', []) as $module => $actions) {
                $permissions[$module] = array_values(array_unique(array_merge($permissions[$module] ?? [], $actions)));
            }
        }

        return $permissions;
    }

    public static function label(?User $user): string
    {
        return config('job_access.jobs.'.($user?->job_role ?? '').'.label') ?: (User::ROLE_LABELS_KO[$user?->access_role] ?? '직원');
    }

    public static function applyEmployeePosition(User $user): void
    {
        $position = config('job_access.jobs.'.$user->job_role.'.position');
        if ($position && $user->employee) {
            $user->employee->forceFill(['position' => $position])->save();
        }
    }

    /** Only the authenticated permission issuer calls this; no client-supplied carrier role is trusted. */
    public static function grant(array $input, ?User $target = null): array
    {
        $job = $input['jobRole'] ?? null;
        if (! is_string($job) || ! array_key_exists($job, config('job_access.jobs'))) {
            throw ValidationException::withMessages(['jobRole' => '직책을 선택하세요.']);
        }
        $duties = $input['jobDuties'] ?? [];
        if (! is_array($duties) || array_diff($duties, array_keys(config('job_access.duties'))) || ($job !== 'office' && $duties)) {
            throw ValidationException::withMessages(['jobDuties' => '사무실 담당 업무를 확인하세요.']);
        }
        $permissions = $input['jobPermissions'] ?? self::defaults($job, $duties);
        if (! is_array($permissions) || array_diff(array_keys($permissions), array_keys(config('job_access.modules')))) {
            throw ValidationException::withMessages(['jobPermissions' => '업무 권한을 확인하세요.']);
        }
        foreach ($permissions as $module => $actions) {
            if (! is_array($actions) || array_diff($actions, array_keys(config('job_access.actions')))) {
                throw ValidationException::withMessages(['jobPermissions' => '업무 권한 값이 올바르지 않습니다.']);
            }
            if ($actions && ! in_array('view', $actions, true)) {
                throw ValidationException::withMessages(['jobPermissions' => '작업 권한에는 조회 권한이 필요합니다.']);
            }
        }
        // Delegation remains a superadmin responsibility; even a president cannot inherit it.
        if (! empty($permissions['system'])) {
            throw ValidationException::withMessages(['jobPermissions' => '계정·조직 설정은 별도 슈퍼관리자 권한입니다.']);
        }
        $company = Company::find((int) ($input['companyId'] ?? 0));
        $scope = $input['scope'] ?? config('job_access.jobs.'.$job.'.scope');
        $ids = $input['siteIds'] ?? (filled($input['siteId'] ?? null) ? [(int) $input['siteId']] : []);
        if (! $company || ! in_array($scope, ['self', 'team', 'trade', 'site', 'company'], true) || ! is_array($ids)) {
            throw ValidationException::withMessages(['companyId' => '소속 회사와 담당 범위를 선택하세요.']);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (in_array($scope, ['site', 'team', 'trade'], true) && ! $ids) {
            throw ValidationException::withMessages(['siteId' => '담당 현장을 선택하세요.']);
        }
        if (Site::whereIn('id', $ids)->where('company_id', $company->id)->count() !== count($ids)) {
            throw ValidationException::withMessages(['siteId' => '선택한 회사의 현장만 지정할 수 있습니다.']);
        }
        $team = filled($input['teamId'] ?? null) ? Team::find((int) $input['teamId']) : null;
        if ($scope === 'team' && (! $team || ! in_array((int) $team->site_id, $ids, true) || (int) $team->company_id !== (int) $company->id)) {
            throw ValidationException::withMessages(['teamId' => '담당 회사·현장의 팀을 선택하세요.']);
        }
        $trade = $scope === 'trade' ? ($input['jobTrade'] ?? null) : null;
        if (($job === 'trade_manager' && $scope !== 'trade') || ($scope === 'trade' && (! is_string($trade) || ! Team::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('site_id', $ids)->where('trade_type', $trade)->exists()))) {
            throw ValidationException::withMessages(['jobTrade' => '공정팀장은 담당 현장과 해당 회사의 공정을 선택하세요.']);
        }
        if ($job === 'worker' && $scope !== 'self') {
            throw ValidationException::withMessages(['scope' => '작업자는 본인 범위로 지정하세요.']);
        }

        return ['job_role' => $job, 'job_duties' => array_values(array_unique($duties)), 'job_permissions' => $permissions,
            'job_site_ids' => $ids, 'access_role' => $target?->access_role === 'super_admin' ? 'super_admin' : ($job === 'worker' ? 'worker' : 'admin'),
            'access_scope' => $scope, 'allowed_company_id' => $company->id, 'allowed_site_id' => $ids[0] ?? null,
            'allowed_team_id' => $scope === 'team' ? $team?->id : null, 'job_trade' => $trade,
            'purchase_request_enabled' => ! empty($permissions['purchasing']),
            'purchase_buy_enabled' => in_array('edit', $permissions['purchasing'] ?? [], true)];
    }

    public static function siteIds(User $user): array
    {
        $query = Site::withoutGlobalScopes()->where('company_id', $user->allowed_company_id ?: 0);
        if ($user->access_scope !== 'company') {
            $query->whereIn('id', $user->job_site_ids ?: array_filter([$user->allowed_site_id]));
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function employeeQuery(User $user)
    {
        return Employee::withoutGlobalScopes()->where(function ($query) use ($user): void {
            $query->whereKey($user->employee_id ?: 0)->orWhere(function ($q) use ($user): void {
                $q->where('company_id', $user->allowed_company_id ?: 0);
                if ($user->access_scope === 'self') {
                    $q->whereKey($user->employee_id ?: 0);
                } elseif ($user->access_scope === 'team') {
                    $q->where('team_id', $user->allowed_team_id ?: 0)->whereIn('site_id', self::siteIds($user));
                } elseif ($user->access_scope === 'trade') {
                    $q->whereIn('team_id', self::teamIds($user))->whereIn('site_id', self::siteIds($user));
                } elseif ($user->access_scope !== 'company') {
                    $q->whereIn('site_id', self::siteIds($user));
                }
            });
        });
    }

    public static function trade(User $user): ?string
    {
        if ($user->access_scope === 'trade') {
            return $user->job_trade;
        }

        return filled($user->allowed_team_id) ? Team::withoutGlobalScopes()->whereKey($user->allowed_team_id)->where('company_id', $user->allowed_company_id)->value('trade_type') : null;
    }

    public static function teamIds(User $user): array
    {
        return Team::withoutGlobalScopes()->where('company_id', $user->allowed_company_id ?: 0)
            ->whereIn('site_id', self::siteIds($user))->where('trade_type', self::trade($user) ?: '__unassigned__')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function catalog(): array
    {
        return config('job_access') + ['trades' => Team::withoutGlobalScopes()->whereNotNull('trade_type')->where('trade_type', '!=', '')
            ->get(['company_id', 'site_id', 'trade_type'])->map(fn ($team) => ['companyId' => $team->company_id, 'siteId' => $team->site_id, 'value' => $team->trade_type])->all()];
    }

    public static function datasetModule(string $key): string
    {
        foreach ([
            'private_hr' => '/^(member_documents|w9_status|applicants)$/',
            'payroll' => '/^(payroll_|payslip)/',
            'finance' => '/^(expenses|expense_preapprovals|project_financials|.*_costs)$/',
            'contracts' => '/^(contracts|contract_|claim_|pay_application|billing_)/',
            'people' => '/^(employees|teams)$/',
            'attendance' => '/^(attendance_|daily_work_assignments|daily_crew_reports)/',
            'purchasing' => '/^(purchase_|procurement|vendors)/',
            'materials' => '/^(equipment|item_|items|material_receipt)/',
            'office' => '/^(vehicle|housing)/',
            'safety' => '/^safety_/',
            'progress' => '/^(wbs_|week_board|submittal|boq_|work_section|drawing_|field_drawing|material_claim)/',
            'documents' => '/^(documents|integrated_documents|document_|knowledge_|email_)/',
            'reports' => '/^(companies$|sites$|projects$|daily_closing|daily_trade|ops_|meetings|report_dispatch|correspondence)/',
            'messages' => '/^(communication_|personal_)/',
        ] as $module => $pattern) {
            if (preg_match($pattern, $key)) {
                return $module;
            }
        }

        return 'system';
    }

    public static function visibleMoneyTypes(User $user): array
    {
        return array_keys(array_filter([
            'payroll_record' => self::can($user, 'payroll'),
            'receipt' => self::can($user, 'finance'), 'invoice' => self::can($user, 'finance'),
            'pay_application' => self::can($user, 'contracts'), 'lien_waiver' => self::can($user, 'contracts'),
            'purchase_order' => self::can($user, 'purchasing', 'edit'),
        ]));
    }

    public static function financialQuestionAllowed(User $user, string $text): bool
    {
        if (! self::managed($user) || $user->access_role === 'super_admin') {
            return AccessPolicy::canManageMoney($user);
        }
        $matched = false;
        foreach (['payroll' => '/급여|임금|시급|월급|연봉|인건비|노무비|payroll|salary|wage/iu',
            'contracts' => '/계약|기성|견적|변경공사|contract|claim|quotation/iu',
            'finance' => '/회계|경비|손익|자금|지출|수금|세금|계좌|accounting|expense|profit|bank|tax/iu'] as $module => $pattern) {
            if (preg_match($pattern, $text)) {
                $matched = true;
                if (! self::can($user, $module)) {
                    return false;
                }
            }
        }

        return $matched || (self::can($user, 'finance') && self::can($user, 'payroll') && self::can($user, 'contracts'));
    }
}
