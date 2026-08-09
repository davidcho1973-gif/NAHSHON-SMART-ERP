<?php

namespace App\Services\Admin;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;

/**
 * 현장 · PROJECT 관리 — Filament SiteResource / ProjectResource 를 SPA 로 옮긴 것.
 *
 * 모든 것이 여기서 시작한다. 현장이 없으면 출퇴근도 공정도 붙을 곳이 없고, 프로젝트가
 * 없으면 공정표를 올릴 대상이 없다. 그래서 등록은 쉽게, 삭제는 어렵게 만든다 —
 * sites 를 참조하는 테이블이 일곱 개이고 전부 연쇄 삭제라 잘못 지우면 협력사 명단·QR·
 * 인원 마감 기록이 함께 사라진다.
 *
 * 타임존은 출퇴근 시각·일일 마감의 기준이라 필수로 받는다. 잘못 넣으면 하루가 밀린다.
 */
class SiteAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];

    public const MANAGE_ROLES = ['super_admin', 'admin'];

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
            return ['success' => false, 'error' => '현장을 볼 권한이 없습니다.'];
        }

        $projectCounts = Project::query()->selectRaw('site_id, count(*) as n')->groupBy('site_id')->pluck('n', 'site_id');
        $crewCounts = Employee::query()->where('employment_status', 'active')
            ->selectRaw('site_id, count(*) as n')->groupBy('site_id')->pluck('n', 'site_id');

        $sites = Site::query()->with(['company:id,name', 'client:id,name'])
            ->orderBy('code')->get()
            ->map(fn (Site $s): array => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'address' => $s->address,
                'country' => $s->country,
                'timezone' => $s->timezone,
                'status' => $s->status,
                'companyId' => $s->company_id,
                'company' => $s->company?->name,
                'clientCompanyId' => $s->client_company_id,
                'clientCompany' => $s->client?->name,
                'projectCount' => (int) ($projectCounts[$s->id] ?? 0),
                'crewCount' => (int) ($crewCounts[$s->id] ?? 0),
                // 지울 수 있는지 미리 알려 준다 — 눌러 보고 거절당하는 것보다 낫다.
                'deletable' => $this->deleteBlocker($s) === null,
                'deleteBlocker' => $this->deleteBlocker($s),
            ])->values()->all();

        $projects = Project::query()->with(['site:id,code', 'endClient:id,name'])
            ->orderByDesc('id')->get()
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'projectCode' => $p->project_code,
                'name' => $p->name,
                'siteId' => $p->site_id,
                'site' => $p->site?->code,
                'constructionType' => $p->construction_type,
                'constructionTypeLabel' => Project::CONSTRUCTION_TYPE_OPTIONS[$p->construction_type] ?? $p->construction_type,
                'stage' => $p->project_stage,
                'stageLabel' => Project::STAGE_OPTIONS[$p->project_stage] ?? $p->project_stage,
                'vendorTier' => $p->vendor_tier,
                'contractType' => $p->contract_type,
                'endClient' => $p->endClient?->name,
                'endClientCompanyId' => $p->end_client_company_id,
                'contractAmount' => $p->contract_amount !== null ? (float) $p->contract_amount : null,
                'poNumber' => $p->po_number,
                'state' => $p->state,
                'ntpDate' => $p->ntp_date?->toDateString(),
                'mobilizationDate' => $p->mobilization_date?->toDateString(),
                'plannedCompletionDate' => $p->planned_completion_date?->toDateString(),
                'actualCompletionDate' => $p->actual_completion_date?->toDateString(),
                'siteAddress' => $p->site_address,
                'jurisdiction' => $p->jurisdiction,
                'scopeOfWork' => $p->scope_of_work,
                'upperContractorCompanyId' => $p->upper_contractor_company_id,
                'epcCompanyId' => $p->epc_company_id,
                'currency' => $p->currency,
                'budgetLabor' => $p->budget_labor_amount !== null ? (float) $p->budget_labor_amount : null,
                'budgetMaterial' => $p->budget_material_amount !== null ? (float) $p->budget_material_amount : null,
                'budgetEquipment' => $p->budget_equipment_amount !== null ? (float) $p->budget_equipment_amount : null,
                'budgetExpense' => $p->budget_expense_amount !== null ? (float) $p->budget_expense_amount : null,
                'retainagePercent' => $p->retainage_percent !== null ? (float) $p->retainage_percent : null,
                'paymentTerms' => $p->payment_terms,
                'perDiemPolicy' => $p->per_diem_policy,
                // 인증임금(WH-347) 대상 여부 — 급여 마감 때 경고를 띄우는 근거다.
                'prevailingWageRequired' => (bool) $p->prevailing_wage_required,
                'davisBaconRequired' => (bool) $p->davis_bacon_required,
                'certifiedPayrollRequired' => (bool) $p->certified_payroll_required,
                'unionStatus' => $p->union_status,
                'ocipCcipStatus' => $p->ocip_ccip_status,
                'oshaPlanStatus' => $p->osha_plan_status,
                'bondingRequired' => (bool) $p->bonding_required,
                'lienNoticeRequired' => (bool) $p->lien_notice_required,
                'preliminaryNoticeDueOn' => $p->preliminary_notice_due_on?->toDateString(),
                'wbsCount' => WbsItem::query()->where('project_code', $p->project_code)->where('level', WbsItem::LEVEL_SUBTASK)->count(),
            ])->values()->all();

        return [
            'success' => true,
            'sites' => $sites,
            'projects' => $projects,
            'canManage' => $this->canManage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '현장을 볼 권한이 없습니다.'];
        }

        $companies = Company::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all();

        return [
            'success' => true,
            'companies' => $companies,
            'countries' => $this->pairs(Site::COUNTRY_OPTIONS),
            'statuses' => [['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive']],
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' · '.$s->name])->all(),
            'constructionTypes' => $this->pairs(Project::CONSTRUCTION_TYPE_OPTIONS),
            'stages' => $this->pairs(Project::STAGE_OPTIONS),
            'vendorTiers' => $this->pairs(Project::VENDOR_TIER_OPTIONS),
            'contractTypes' => $this->pairs(Project::CONTRACT_TYPE_OPTIONS),
            'unionStatuses' => $this->pairs(Project::UNION_STATUS_OPTIONS),
            'ocipStatuses' => $this->pairs(Project::OCIP_CCIP_OPTIONS),
            'oshaStatuses' => $this->pairs(Project::OSHA_PLAN_OPTIONS),
            'currencies' => [['value' => 'USD', 'label' => 'USD'], ['value' => 'KRW', 'label' => 'KRW']],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveSite(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '현장을 등록·수정할 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $site = $id > 0 ? Site::query()->find($id) : new Site;
        if ($id > 0 && ! $site) {
            return ['success' => false, 'error' => '현장을 찾을 수 없습니다.'];
        }

        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? ''));

        if ($code === '' || $name === '') {
            return ['success' => false, 'error' => '현장 코드와 현장명은 필수입니다.'];
        }
        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return ['success' => false, 'error' => '타임존이 올바르지 않습니다 (예: America/Phoenix). 출퇴근 시각의 기준이라 정확해야 합니다.'];
        }
        if (Site::query()->where('code', $code)->when($id > 0, fn ($q) => $q->whereKeyNot($id))->exists()) {
            return ['success' => false, 'error' => "이미 쓰고 있는 현장 코드입니다: {$code}"];
        }

        $site->fill([
            'code' => $code,
            'name' => $name,
            'address' => $this->text($input['address'] ?? null),
            'country' => isset(Site::COUNTRY_OPTIONS[$input['country'] ?? '']) ? $input['country'] : ($site->country ?: 'US'),
            'timezone' => $timezone,
            'status' => in_array($input['status'] ?? '', ['active', 'inactive'], true) ? $input['status'] : ($site->status ?: 'active'),
            'company_id' => ($input['company_id'] ?? '') !== '' ? (int) $input['company_id'] : null,
            'client_company_id' => ($input['client_company_id'] ?? '') !== '' ? (int) $input['client_company_id'] : null,
        ]);
        $site->save();

        return ['success' => true, 'id' => $site->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveProject(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '프로젝트를 등록·수정할 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $project = $id > 0 ? Project::query()->find($id) : new Project;
        if ($id > 0 && ! $project) {
            return ['success' => false, 'error' => '프로젝트를 찾을 수 없습니다.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $siteId = (int) ($input['site_id'] ?? 0);
        if ($name === '') {
            return ['success' => false, 'error' => '프로젝트명은 필수입니다.'];
        }
        $site = Site::query()->find($siteId);
        if (! $site) {
            return ['success' => false, 'error' => '현장을 선택해 주세요. 프로젝트는 반드시 현장에 속합니다.'];
        }

        $code = strtoupper(trim((string) ($input['project_code'] ?? '')));
        if ($code === '') {
            $code = $this->generateCode($site, (string) ($input['state'] ?? ''));
        }
        if (Project::query()->where('project_code', $code)->when($id > 0, fn ($q) => $q->whereKeyNot($id))->exists()) {
            return ['success' => false, 'error' => "이미 쓰고 있는 프로젝트 코드입니다: {$code}"];
        }
        // 공정표·조달·사진이 전부 project_code 로 붙는다. 코드를 바꾸면 그 연결이 끊긴다.
        if ($id > 0 && $project->project_code !== $code
            && WbsItem::query()->where('project_code', $project->project_code)->exists()) {
            return ['success' => false, 'error' => '공정표가 등록된 프로젝트는 코드를 바꿀 수 없습니다. 공정·조달·사진이 이 코드로 연결돼 있습니다.'];
        }

        $type = (string) ($input['construction_type'] ?? '');
        if (! isset(Project::CONSTRUCTION_TYPE_OPTIONS[$type])) {
            return ['success' => false, 'error' => '공사 유형을 선택해 주세요.'];
        }

        $project->fill([
            'project_code' => $code,
            'name' => $name,
            'site_id' => $site->id,
            'company_id' => $project->company_id ?: $site->company_id,
            'construction_type' => $type,
            'project_stage' => isset(Project::STAGE_OPTIONS[$input['project_stage'] ?? '']) ? $input['project_stage'] : ($project->project_stage ?: 'estimate'),
            'vendor_tier' => isset(Project::VENDOR_TIER_OPTIONS[$input['vendor_tier'] ?? '']) ? $input['vendor_tier'] : null,
            'contract_type' => isset(Project::CONTRACT_TYPE_OPTIONS[$input['contract_type'] ?? '']) ? $input['contract_type'] : null,
            'end_client_company_id' => ($input['end_client_company_id'] ?? '') !== '' ? (int) $input['end_client_company_id'] : null,
            'po_number' => $this->text($input['po_number'] ?? null, upper: true),
            'state' => $this->text($input['state'] ?? null, upper: true),
            'site_address' => $this->text($input['site_address'] ?? null),
            'contract_amount' => is_numeric($input['contract_amount'] ?? null) ? (float) $input['contract_amount'] : null,
            'ntp_date' => $this->text($input['ntp_date'] ?? null),
            'mobilization_date' => $this->text($input['mobilization_date'] ?? null),
            'planned_completion_date' => $this->text($input['planned_completion_date'] ?? null),
            'actual_completion_date' => $this->text($input['actual_completion_date'] ?? null),
            'scope_of_work' => $this->text($input['scope_of_work'] ?? null),
            'jurisdiction' => $this->text($input['jurisdiction'] ?? null),
            'upper_contractor_company_id' => ($input['upper_contractor_company_id'] ?? '') !== '' ? (int) $input['upper_contractor_company_id'] : null,
            'epc_company_id' => ($input['epc_company_id'] ?? '') !== '' ? (int) $input['epc_company_id'] : null,

            // 예산은 원가 대비 실적을 보는 기준선이다. 비워 두면 "얼마나 넘었나"를 말할 수 없다.
            'currency' => in_array($input['currency'] ?? '', ['USD', 'KRW'], true) ? $input['currency'] : ($project->currency ?: 'USD'),
            'budget_labor_amount' => $this->money($input['budget_labor_amount'] ?? null),
            'budget_material_amount' => $this->money($input['budget_material_amount'] ?? null),
            'budget_equipment_amount' => $this->money($input['budget_equipment_amount'] ?? null),
            'budget_expense_amount' => $this->money($input['budget_expense_amount'] ?? null),
            'retainage_percent' => $this->money($input['retainage_percent'] ?? null),
            'payment_terms' => $this->text($input['payment_terms'] ?? null),

            // 규정 항목 — 인증임금(WH-347) 제출 대상인지를 여기서 정한다.
            // CertifiedPayrollRequirement 가 이 두 값을 읽어 급여 마감 때 경고를 띄운다.
            'prevailing_wage_required' => (bool) ($input['prevailing_wage_required'] ?? false),
            'davis_bacon_required' => (bool) ($input['davis_bacon_required'] ?? false),
            'certified_payroll_required' => (bool) ($input['certified_payroll_required'] ?? false),
            'union_status' => isset(Project::UNION_STATUS_OPTIONS[$input['union_status'] ?? '']) ? $input['union_status'] : null,
            'ocip_ccip_status' => isset(Project::OCIP_CCIP_OPTIONS[$input['ocip_ccip_status'] ?? '']) ? $input['ocip_ccip_status'] : null,
            'osha_plan_status' => isset(Project::OSHA_PLAN_OPTIONS[$input['osha_plan_status'] ?? '']) ? $input['osha_plan_status'] : null,
            'bonding_required' => (bool) ($input['bonding_required'] ?? false),
            'lien_notice_required' => (bool) ($input['lien_notice_required'] ?? false),
            'preliminary_notice_due_on' => $this->text($input['preliminary_notice_due_on'] ?? null),
            'per_diem_policy' => $this->text($input['per_diem_policy'] ?? null),
        ]);
        // milestone_plan · workforce_plan 같은 표 형태 항목은 여기서 건드리지 않는다 —
        // fill() 에 없으면 기존 값이 그대로 남는다.
        $project->save();

        return ['success' => true, 'id' => $project->id, 'projectCode' => $project->project_code];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSite(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '현장을 삭제할 권한이 없습니다.'];
        }

        $site = Site::query()->find($id);
        if (! $site) {
            return ['success' => false, 'error' => '현장을 찾을 수 없습니다.'];
        }

        if ($blocker = $this->deleteBlocker($site)) {
            return ['success' => false, 'error' => $blocker];
        }

        $site->delete();

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteProject(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '프로젝트를 삭제할 권한이 없습니다.'];
        }

        $project = Project::query()->find($id);
        if (! $project) {
            return ['success' => false, 'error' => '프로젝트를 찾을 수 없습니다.'];
        }

        $wbs = WbsItem::query()->where('project_code', $project->project_code)->count();
        if ($wbs > 0) {
            return ['success' => false, 'error' => "공정표 {$wbs}건이 등록된 프로젝트입니다. 공정관리에서 먼저 정리해 주세요."];
        }

        $project->delete();

        return ['success' => true];
    }

    /**
     * 현장을 지울 수 없는 이유. 없으면 null.
     *
     * sites 는 일곱 개 테이블에서 연쇄 삭제로 참조된다 — 협력사 명단·QR 코드·WiFi·
     * 인원 마감까지 함께 사라지므로, 기록이 하나라도 있으면 막고 "비활성"을 권한다.
     */
    private function deleteBlocker(Site $site): ?string
    {
        if (Project::query()->where('site_id', $site->id)->exists()) {
            return '이 현장에 프로젝트가 있습니다. 프로젝트를 먼저 정리해 주세요.';
        }
        if (AttendanceLog::query()->where('site_id', $site->id)->exists()) {
            return '출퇴근 기록이 있는 현장은 지울 수 없습니다. 상태를 Inactive 로 바꿔 주세요.';
        }
        if (AttendanceQrCode::query()->where('site_id', $site->id)->exists()) {
            return 'QR 코드가 발급된 현장입니다. 상태를 Inactive 로 바꿔 주세요.';
        }
        if (Employee::query()->where('site_id', $site->id)->exists()) {
            return '이 현장에 배정된 직원이 있습니다. 먼저 다른 현장으로 옮겨 주세요.';
        }

        return null;
    }

    /** 코드를 비우면 발주처·주·연도로 만든다 — 사람이 규칙을 외울 필요가 없다. */
    private function generateCode(Site $site, string $state): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $site->code) ?: 'PRJ');
        $st = strtoupper(trim($state)) ?: strtoupper((string) $site->country);
        $year = now()->format('Y');
        $prefix = trim("{$base}-{$st}-{$year}", '-');

        $n = Project::query()->where('project_code', 'like', $prefix.'-%')->count() + 1;

        do {
            $candidate = sprintf('%s-%03d', $prefix, $n);
            $n++;
        } while (Project::query()->where('project_code', $candidate)->exists());

        return $candidate;
    }

    /** @param array<string, string> $map */
    private function pairs(array $map): array
    {
        return array_map(fn ($k, $v) => ['value' => (string) $k, 'label' => $v], array_keys($map), array_values($map));
    }

    private function money(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function text(mixed $v, bool $upper = false): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }

        return $upper ? strtoupper($s) : $s;
    }
}
