<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Company and team masters were never given a replacement editor after the old panel was removed. */
class CrewSetupService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager'];

    public const COMPANY_ROLES = ['super_admin', 'admin'];

    private function allowed(array $roles): bool
    {
        return auth()->user()?->account_status === 'active'
            && in_array(auth()->user()?->access_role, $roles, true);
    }

    private function scope($query): void
    {
        $u = auth()->user();
        if ($this->allowed(self::COMPANY_ROLES)) {
            return;
        }
        match ($u?->access_scope) {
            'company' => $query->where('company_id', $u->allowed_company_id ?: -1),
            'site' => $query->where('site_id', $u->allowed_site_id ?: -1),
            'team' => $query->where('team_id', $u->allowed_team_id ?: -1),
            // HR may explicitly manage all sites; a site manager never inherits this bypass.
            'all_sites' => $u->access_role === 'hr_manager' ? null : $query->whereRaw('1=0'),
            default => $query->whereRaw('1=0'),
        };
    }

    private function canPlace(int $companyId, int $siteId): bool
    {
        if ($this->allowed(self::COMPANY_ROLES)) {
            return true;
        }
        $u = auth()->user();

        return match ($u?->access_scope) {
            'company' => $companyId === (int) $u->allowed_company_id,
            'site' => $siteId === (int) $u->allowed_site_id,
            'all_sites' => $u->access_role === 'hr_manager',
            default => false,
        };
    }

    public function overview(): array
    {
        if (! $this->allowed(self::VIEW_ROLES)) {
            return ['success' => false, 'error' => '회사·팀 조회 권한이 없습니다.'];
        }
        $employees = Employee::query()->with('user:id,employee_id,access_role,access_scope,allowed_team_id,account_status')->orderBy('name');
        $this->scope($employees);
        $teams = Team::query()->with(['company:id,name', 'site:id,code', 'foreman:id,name'])->withCount('employees')->orderBy('name');
        $u = auth()->user();
        if (! $this->allowed(self::COMPANY_ROLES)) {
            if ($u->access_scope === 'team') {
                $teams->whereKey($u->allowed_team_id ?: -1);
            } else {
                $this->scope($teams);
            }
        }
        $companies = Company::query()->orderBy('name');
        $sites = Site::query()->orderBy('code');
        if (! $this->allowed(self::COMPANY_ROLES) && ! ($u->access_role === 'hr_manager' && $u->access_scope === 'all_sites')) {
            if ($u->access_scope === 'company') {
                $companies->whereKey($u->allowed_company_id ?: -1);
                $sites->where(function ($q) use ($u) {
                    $q->where('company_id', $u->allowed_company_id ?: -1)
                        ->orWhereHas('companies', fn ($c) => $c->where('companies.id', $u->allowed_company_id ?: -1));
                });
            } elseif ($u->access_scope === 'site') {
                $sites->whereKey($u->allowed_site_id ?: -1);
                $companies->where(function ($q) use ($u) {
                    $q->whereHas('sites', fn ($s) => $s->where('sites.id', $u->allowed_site_id ?: -1))
                        ->orWhereIn('id', Site::whereKey($u->allowed_site_id ?: -1)->select('company_id'))
                        ->orWhereIn('id', Employee::where('site_id', $u->allowed_site_id ?: -1)->select('company_id'));
                });
            } else {
                $companies->whereRaw('1=0');
                $sites->whereRaw('1=0');
            }
        }

        return [
            'success' => true,
            'canManage' => $this->allowed(self::MANAGE_ROLES),
            'canManageCompanies' => $this->allowed(self::COMPANY_ROLES),
            'defaultCompanyId' => CurrentCompany::id(),
            'companyTypes' => Company::COMPANY_TYPES,
            'companies' => $companies->get(['id', 'code', 'name', 'legal_name', 'company_type', 'status']),
            'sites' => $sites->get(['id', 'code', 'name', 'status']),
            'teams' => $teams->get()->map(fn (Team $t) => [
                'id' => $t->id, 'code' => $t->code, 'name' => $t->name,
                'companyId' => $t->company_id, 'company' => $t->company?->name,
                'siteId' => $t->site_id, 'site' => $t->site?->code,
                'trade' => $t->trade_type, 'foremanId' => $t->foreman_employee_id,
                'foreman' => $t->foreman_name, 'planned' => $t->planned_headcount,
                'members' => $t->employees_count, 'status' => $t->status,
            ]),
            'employees' => $employees->get()->map(fn (Employee $e) => [
                'id' => $e->id, 'name' => $e->name, 'companyId' => $e->company_id,
                'siteId' => $e->site_id, 'teamId' => $e->team_id, 'status' => $e->employment_status,
                'accountRole' => $e->user?->access_role, 'accountStatus' => $e->user?->account_status,
                'accountScope' => $e->user?->access_scope, 'accountTeamId' => $e->user?->allowed_team_id,
                'qrRole' => $e->attendance_app_role,
            ]),
        ];
    }

    public function saveCompany(array $input): array
    {
        if (! $this->allowed(self::COMPANY_ROLES)) {
            return ['success' => false, 'error' => '회사 등록·분류는 관리자만 할 수 있습니다.'];
        }
        $id = (int) ($input['id'] ?? 0);
        $row = $id ? Company::find($id) : new Company;
        if (! $row) {
            return ['success' => false, 'error' => '회사를 찾을 수 없습니다.'];
        }
        $data = [
            'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'legal_name' => trim((string) ($input['legal_name'] ?? '')) ?: null,
            'company_type' => $input['company_type'] ?? '', 'status' => $input['status'] ?? 'active',
        ];
        $v = Validator::make($data, [
            'code' => ['required', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('companies', 'code')->ignore($id)],
            'name' => ['required', 'max:120'], 'legal_name' => ['nullable', 'max:255'],
            'company_type' => ['required', Rule::in(array_keys(Company::COMPANY_TYPES))],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if (Company::whereRaw('lower(trim(name)) = ?', [mb_strtolower($data['name'])])->where('id', '!=', $id)->exists()) {
            $v->after(fn ($v) => $v->errors()->add('name', '같은 이름의 회사가 이미 있습니다.'));
        }
        if ($id && $row->code !== $data['code']) {
            $v->after(fn ($v) => $v->errors()->add('code', '기존 회사 코드는 변경할 수 없습니다.'));
        }
        if ($v->fails()) {
            return ['success' => false, 'errors' => $v->errors()->messages()];
        }
        $row->fill($data)->save();

        return ['success' => true, 'id' => $row->id];
    }

    public function saveTeam(array $input): array
    {
        if (! $this->allowed(self::MANAGE_ROLES)) {
            return ['success' => false, 'error' => '팀 등록 권한이 없습니다.'];
        }

        return DB::transaction(function () use ($input) {
            $id = (int) ($input['id'] ?? 0);
            $team = $id ? Team::lockForUpdate()->find($id) : new Team;
            if (! $team || ($id && ! $this->canPlace((int) $team->company_id, (int) $team->site_id))) {
                return ['success' => false, 'error' => '담당 범위의 팀이 아닙니다.'];
            }
            $company = (int) ($input['companyId'] ?? 0);
            $site = (int) ($input['siteId'] ?? 0);
            if (! $this->canPlace($company, $site)) {
                return ['success' => false, 'error' => '담당 회사·현장 범위 밖입니다.'];
            }
            $data = [
                'name' => trim((string) ($input['name'] ?? '')),
                'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
                'company_id' => $company, 'site_id' => $site,
                'trade_type' => trim((string) ($input['trade'] ?? '')),
                'planned_headcount' => $input['planned'] ?? 0,
                'status' => $input['status'] ?? 'active',
                'foreman_employee_id' => ($input['foremanId'] ?? null) ?: null,
            ];
            $v = Validator::make($data, [
                'name' => ['required', 'max:120'],
                'code' => ['required', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('teams', 'code')->ignore($id)],
                'company_id' => ['required', Rule::exists('companies', 'id')->where('status', 'active')],
                'site_id' => ['required', Rule::exists('sites', 'id')->where('status', 'active')],
                'trade_type' => ['required', 'max:80'], 'planned_headcount' => ['required', 'integer', 'min:0', 'max:10000'],
                'status' => [Rule::in(['active', 'inactive'])], 'foreman_employee_id' => ['nullable', 'integer'],
            ]);
            if ($id && ($team->company_id != $company || $team->site_id != $site)) {
                $v->after(fn ($v) => $v->errors()->add('name', '기존 팀의 회사·현장은 변경할 수 없습니다. 새 팀을 등록하세요.'));
            }
            $foreman = $data['foreman_employee_id'] ? Employee::lockForUpdate()->find($data['foreman_employee_id']) : null;
            if ($data['foreman_employee_id'] && (! $foreman || $foreman->company_id != $company || $foreman->site_id != $site
                || $foreman->employment_status !== 'active' || ($foreman->team_id && $foreman->team_id != $id))) {
                $v->after(fn ($v) => $v->errors()->add('foremanId', '반장은 같은 회사·현장의 재직자이며 미배치 또는 이 팀 소속이어야 합니다.'));
            }
            if (Team::where('company_id', $company)->where('site_id', $site)->whereRaw('lower(trim(name)) = ?', [mb_strtolower($data['name'])])->where('id', '!=', $id)->exists()) {
                $v->after(fn ($v) => $v->errors()->add('name', '같은 회사·현장에 동일한 팀명이 있습니다.'));
            }
            if ($foreman?->user?->access_scope === 'team' && (! $id || (int) $foreman->user->allowed_team_id !== $id)) {
                $v->after(fn ($v) => $v->errors()->add('foremanId', '계정·권한 관리에서 반장의 팀 접근 범위를 먼저 확인하세요.'));
            }
            if ($v->fails()) {
                $names = ['company_id' => 'companyId', 'site_id' => 'siteId', 'trade_type' => 'trade', 'planned_headcount' => 'planned', 'foreman_employee_id' => 'foremanId'];
                $errors = [];
                foreach ($v->errors()->messages() as $key => $messages) {
                    $errors[$names[$key] ?? $key] = implode(' ', $messages);
                }

                return ['success' => false, 'errors' => $errors];
            }
            $team->fill($data);
            // Preserve unlinked legacy names until a person is selected; clearing a linked person is explicit.
            if ($foreman || $team->getOriginal('foreman_employee_id')) {
                $team->foreman_name = null;
            }
            $team->save();
            Company::findOrFail($company)->sites()->syncWithoutDetaching([$site]);
            if ($foreman && ! $foreman->team_id) {
                $foreman->update(['team_id' => $team->id]);
            }

            return ['success' => true, 'id' => $team->id];
        });
    }

    public function assign(array $input): array
    {
        if (! $this->allowed(self::MANAGE_ROLES)) {
            return ['success' => false, 'error' => '작업자 배치 권한이 없습니다.'];
        }

        return DB::transaction(function () use ($input) {
            $e = Employee::lockForUpdate()->find((int) ($input['employeeId'] ?? 0));
            if (! $e || ! $this->canPlace((int) $e->company_id, (int) $e->site_id)) {
                return ['success' => false, 'error' => '담당 범위의 직원이 아닙니다.'];
            }
            $teamId = (int) ($input['teamId'] ?? 0);
            $t = $teamId ? Team::lockForUpdate()->find($teamId) : null;
            if ($teamId && (! $t || $t->status !== 'active' || $e->employment_status !== 'active'
                || $t->company_id != $e->company_id || $t->site_id != $e->site_id)) {
                return ['success' => false, 'errors' => ['teamId' => '같은 회사·현장의 활성 팀만 선택할 수 있습니다.']];
            }
            if ($e->team_id != $teamId && Team::where('foreman_employee_id', $e->id)->exists()) {
                return ['success' => false, 'error' => '담당 반장 지정부터 해제하거나 변경하세요.'];
            }
            // A roster move must not silently move an account's independently approved access scope.
            $account = $e->user;
            if ($account && $account->access_scope === 'team' && (int) $account->allowed_team_id !== $teamId) {
                return ['success' => false, 'error' => '계정·권한 관리에서 팀 접근 범위를 먼저 확인·변경한 뒤 배치하세요.'];
            }
            $e->update(['team_id' => $teamId ?: null]);

            return ['success' => true];
        });
    }
}
