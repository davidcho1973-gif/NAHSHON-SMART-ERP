<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * 원청 계약 · 서류 — Filament ProjectContractResource 를 SPA 로 옮긴 것.
 *
 * 계약은 "지금 유효한가" 와 "다음에 뭘 해야 하나" 가 전부다. 종료일이 지났는데 상태가
 * 여전히 유효로 남아 있거나, 갱신 통보 기한이 코앞인데 아무도 모르는 상황이 실제 손해로
 * 이어진다. 그래서 목록이 만료·갱신 임박·다음 조치를 먼저 보여준다.
 *
 * 서류는 버전이 생명이다. 같은 종류의 새 서류를 올리면 이전 것을 "대체됨" 으로 내리고
 * 최신본만 현재로 둔다 — 어느 것이 최신인지 모르는 서류철이 가장 위험하다.
 */
class ContractAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'site_manager', 'payroll'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager'];

    public const DELETE_ROLES = ['super_admin', 'admin'];

    /** 계약 서류는 원청 도면·금액이 담기므로 용량을 넉넉히 두되 무제한은 아니다. */
    public const MAX_KB = 32768;

    /**
     * NOT NULL 인데 DB 기본값이 있는 칸. 비었을 때 null 을 쓰면 제약 위반이 나므로
     * 키 자체를 빼서 DB 기본값이 들어가게 한다.
     *
     * @var array<int, string>
     */
    private const DB_DEFAULTED = [
        'direction', 'contract_type', 'status', 'risk_level',
        'renewal_notice_days', 'approved_change_amount', 'currency',
    ];

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    private function disk(): string
    {
        return (string) config('document-intelligence.disk', 'local');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '계약 조회 권한이 없습니다.'];
        }

        $query = ProjectContract::query()
            ->with(['company:id,name', 'counterparty:id,name', 'site:id,code', 'project:id,name'])
            ->withCount('documents')
            ->orderByDesc('updated_at');
        $this->applyScope($query);

        $status = trim((string) ($filters['status'] ?? ''));
        if (array_key_exists($status, ProjectContract::STATUS_OPTIONS)) {
            $query->where('status', $status);
        }
        if ($siteId = $this->intOrNull($filters['siteId'] ?? null)) {
            $query->where('site_id', $siteId);
        }
        $direction = trim((string) ($filters['direction'] ?? ''));
        if (array_key_exists($direction, ProjectContract::DIRECTION_OPTIONS)) {
            $query->where('direction', $direction);
        }

        $today = Carbon::now()->toDateString();

        $rows = $query->get()->map(function (ProjectContract $c) use ($today): array {
            // 종료일·갱신 통보 기한·다음 조치일 중 지났거나 임박한 것을 모아 올린다.
            $alerts = [];
            $ends = $c->ends_on?->toDateString();
            if ($ends) {
                if ($ends < $today) {
                    $alerts[] = ['label' => '계약 종료', 'date' => $ends, 'state' => 'expired'];
                } elseif ($ends <= Carbon::now()->addDays(60)->toDateString()) {
                    $alerts[] = ['label' => '종료 임박', 'date' => $ends, 'state' => 'soon'];
                }
            }
            // 갱신 통보는 "종료일 - 통보일수" 가 지나면 이미 늦은 것이다.
            if ($ends && $c->renewal_notice_days) {
                $deadline = Carbon::parse($ends)->subDays((int) $c->renewal_notice_days)->toDateString();
                if ($deadline <= $today && $ends >= $today) {
                    $alerts[] = ['label' => '갱신 통보 기한', 'date' => $deadline, 'state' => 'expired'];
                }
            }
            $next = $c->next_action_on?->toDateString();
            if ($next && $next <= Carbon::now()->addDays(14)->toDateString()) {
                $alerts[] = ['label' => '다음 조치', 'date' => $next, 'state' => $next < $today ? 'expired' : 'soon'];
            }

            return [
                'id' => $c->id,
                'title' => $c->title,
                'contractNumber' => $c->contract_number,
                'internalReference' => $c->internal_reference,
                'direction' => $c->direction,
                'directionLabel' => ProjectContract::DIRECTION_OPTIONS[$c->direction] ?? (string) $c->direction,
                'contractType' => $c->contract_type,
                'contractTypeLabel' => ProjectContract::TYPE_OPTIONS[$c->contract_type] ?? (string) $c->contract_type,
                'counterpartyRole' => $c->counterparty_role,
                'counterparty' => $c->counterparty?->name,
                'counterpartyId' => $c->counterparty_company_id,
                'companyId' => $c->company_id,
                'company' => $c->company?->name,
                'siteId' => $c->site_id,
                'site' => $c->site?->code,
                'projectId' => $c->project_id,
                'project' => $c->project?->name,
                'managerEmployeeId' => $c->manager_employee_id,
                'status' => $c->status,
                'statusLabel' => ProjectContract::STATUS_OPTIONS[$c->status] ?? (string) $c->status,
                'riskLevel' => $c->risk_level,
                'riskLabel' => ProjectContract::RISK_OPTIONS[$c->risk_level] ?? null,
                'currentAmount' => $c->current_amount !== null ? (float) $c->current_amount : null,
                'originalAmount' => $c->original_amount !== null ? (float) $c->original_amount : null,
                'approvedChangeAmount' => $c->approved_change_amount !== null ? (float) $c->approved_change_amount : null,
                'currency' => $c->currency ?: 'USD',
                'retainagePercent' => $c->retainage_percent !== null ? (float) $c->retainage_percent : null,
                'paymentTerms' => $c->payment_terms,
                'executedOn' => $c->executed_on?->toDateString(),
                'effectiveOn' => $c->effective_on?->toDateString(),
                'startsOn' => $c->starts_on?->toDateString(),
                'endsOn' => $ends,
                'noticeToProceedOn' => $c->notice_to_proceed_on?->toDateString(),
                'renewalNoticeDays' => $c->renewal_notice_days,
                'nextActionOn' => $next,
                'nextActionNotes' => $c->next_action_notes,
                'scopeOfWork' => $c->scope_of_work,
                'notes' => $c->notes,
                'contactName' => $c->counterparty_contact_name,
                'contactEmail' => $c->counterparty_contact_email,
                'contactPhone' => $c->counterparty_contact_phone,
                'insuranceRequired' => (bool) $c->insurance_required,
                'bondRequired' => (bool) $c->bond_required,
                'prevailingWageRequired' => (bool) $c->prevailing_wage_required,
                'certifiedPayrollRequired' => (bool) $c->certified_payroll_required,
                'documentCount' => $c->documents_count,
                'alerts' => $alerts,
            ];
        })->values()->all();

        return ['success' => true, 'rows' => $rows, 'canManage' => $this->canManage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '계약 조회 권한이 없습니다.'];
        }

        $pairs = fn (array $map): array => array_map(
            fn ($k, $v): array => ['value' => (string) $k, 'label' => $v],
            array_keys($map),
            array_values($map),
        );

        return [
            'success' => true,
            'directions' => $pairs(ProjectContract::DIRECTION_OPTIONS),
            'counterpartyRoles' => $pairs(ProjectContract::COUNTERPARTY_ROLE_OPTIONS),
            'contractTypes' => $pairs(ProjectContract::TYPE_OPTIONS),
            'statuses' => $pairs(ProjectContract::STATUS_OPTIONS),
            'risks' => $pairs(ProjectContract::RISK_OPTIONS),
            'documentTypes' => $pairs(ProjectContractDocument::TYPE_OPTIONS),
            'documentStatuses' => $pairs(ProjectContractDocument::STATUS_OPTIONS),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' — '.$s->name])->all(),
            'projects' => Project::query()->orderByDesc('id')->get(['id', 'name', 'project_code'])
                ->map(fn (Project $p): array => ['value' => (string) $p->id, 'label' => ($p->project_code ? $p->project_code.' — ' : '').$p->name])->all(),
            'managers' => Employee::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Employee $e): array => ['value' => (string) $e->id, 'label' => $e->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '계약 수정 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = $id > 0 ? ProjectContract::find($id) : null;
        if ($id > 0 && ! $row) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }

        $title = trim((string) ($input['title'] ?? ''));
        $direction = (string) ($input['direction'] ?? '');
        $status = (string) ($input['status'] ?? 'draft');
        $type = (string) ($input['contractType'] ?? '');

        $errors = [];
        if ($title === '') {
            $errors['title'] = '계약명을 입력하세요.';
        }
        if (! array_key_exists($direction, ProjectContract::DIRECTION_OPTIONS)) {
            $errors['direction'] = '계약 방향을 선택하세요.';
        }
        if (! array_key_exists($status, ProjectContract::STATUS_OPTIONS)) {
            $errors['status'] = '상태를 선택하세요.';
        }
        if ($type !== '' && ! array_key_exists($type, ProjectContract::TYPE_OPTIONS)) {
            $errors['contractType'] = '계약 종류를 선택하세요.';
        }

        $companyId = $this->intOrNull($input['companyId'] ?? null);
        if (! $companyId) {
            $errors['companyId'] = '자사(계약 주체)를 선택하세요.';
        }
        $counterpartyId = $this->intOrNull($input['counterpartyId'] ?? null);
        if ($counterpartyId && $counterpartyId === $companyId) {
            $errors['counterpartyId'] = '자기 회사와는 계약할 수 없습니다.';
        }

        $dates = [];
        foreach (['executedOn', 'effectiveOn', 'startsOn', 'endsOn', 'noticeToProceedOn', 'nextActionOn'] as $k) {
            $v = trim((string) ($input[$k] ?? ''));
            if ($v === '') {
                $dates[$k] = null;

                continue;
            }
            try {
                $dates[$k] = Carbon::parse($v)->toDateString();
            } catch (\Throwable) {
                $errors[$k] = '날짜 형식이 올바르지 않습니다.';
            }
        }

        // 끝나는 날이 시작보다 앞서면 기간 계산과 만료 경고가 전부 어긋난다.
        if (! empty($dates['startsOn']) && ! empty($dates['endsOn']) && $dates['endsOn'] < $dates['startsOn']) {
            $errors['endsOn'] = '종료일이 시작일보다 앞설 수 없습니다.';
        }

        $nums = [];
        foreach (['originalAmount' => '계약 금액', 'approvedChangeAmount' => '승인된 변경 금액',
            'currentAmount' => '현재 금액', 'retainagePercent' => '유보율'] as $k => $label) {
            $v = trim((string) ($input[$k] ?? ''));
            if ($v === '') {
                $nums[$k] = null;

                continue;
            }
            if (! is_numeric($v)) {
                $errors[$k] = $label.'은(는) 숫자만 입력하세요.';

                continue;
            }
            $nums[$k] = (float) $v;
        }
        if (($nums['retainagePercent'] ?? null) !== null && ($nums['retainagePercent'] < 0 || $nums['retainagePercent'] > 100)) {
            $errors['retainagePercent'] = '유보율은 0~100 사이여야 합니다.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // 현재 금액을 안 적으면 계약 금액 + 승인된 변경 금액으로 채운다.
        // 손으로 더하다 틀리는 일이 잦은 자리다.
        $current = $nums['currentAmount'];
        if ($current === null && $nums['originalAmount'] !== null) {
            $current = $nums['originalAmount'] + (float) ($nums['approvedChangeAmount'] ?? 0);
        }

        $data = [
            'company_id' => $companyId,
            'counterparty_company_id' => $counterpartyId,
            'site_id' => $this->intOrNull($input['siteId'] ?? null),
            'project_id' => $this->intOrNull($input['projectId'] ?? null),
            'manager_employee_id' => $this->intOrNull($input['managerEmployeeId'] ?? null),
            'internal_reference' => trim((string) ($input['internalReference'] ?? '')) ?: null,
            'contract_number' => trim((string) ($input['contractNumber'] ?? '')) ?: null,
            'title' => mb_substr($title, 0, 255),
            'direction' => $direction,
            'counterparty_role' => (string) ($input['counterpartyRole'] ?? '') ?: null,
            'contract_type' => $type ?: null,
            'status' => $status,
            'risk_level' => (string) ($input['riskLevel'] ?? '') ?: null,
            'executed_on' => $dates['executedOn'],
            'effective_on' => $dates['effectiveOn'],
            'starts_on' => $dates['startsOn'],
            'ends_on' => $dates['endsOn'],
            'notice_to_proceed_on' => $dates['noticeToProceedOn'],
            'renewal_notice_days' => $this->intOrNull($input['renewalNoticeDays'] ?? null),
            'next_action_on' => $dates['nextActionOn'],
            'next_action_notes' => trim((string) ($input['nextActionNotes'] ?? '')) ?: null,
            'original_amount' => $nums['originalAmount'],
            'approved_change_amount' => $nums['approvedChangeAmount'],
            'current_amount' => $current,
            'currency' => trim((string) ($input['currency'] ?? '')) ?: 'USD',
            'retainage_percent' => $nums['retainagePercent'],
            'payment_terms' => trim((string) ($input['paymentTerms'] ?? '')) ?: null,
            'insurance_required' => (bool) ($input['insuranceRequired'] ?? false),
            'bond_required' => (bool) ($input['bondRequired'] ?? false),
            'prevailing_wage_required' => (bool) ($input['prevailingWageRequired'] ?? false),
            'certified_payroll_required' => (bool) ($input['certifiedPayrollRequired'] ?? false),
            'counterparty_contact_name' => trim((string) ($input['contactName'] ?? '')) ?: null,
            'counterparty_contact_email' => trim((string) ($input['contactEmail'] ?? '')) ?: null,
            'counterparty_contact_phone' => trim((string) ($input['contactPhone'] ?? '')) ?: null,
            'scope_of_work' => trim((string) ($input['scopeOfWork'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        // NOT NULL 이면서 DB 기본값이 있는 칸들 — null 을 넣으면 기본값이 적용되는 게 아니라
        // 제약 위반이 난다. 값이 없으면 키를 빼서 DB 가 자기 기본값을 쓰게 둔다.
        foreach (self::DB_DEFAULTED as $col) {
            if (($data[$col] ?? null) === null) {
                unset($data[$col]);
            }
        }

        if ($row) {
            $row->update($data);

            return ['success' => true, 'id' => $row->id];
        }

        return ['success' => true, 'id' => ProjectContract::create($data)->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $actor = auth()->user();
        if (! $actor || $actor->account_status !== 'active' || ! in_array($actor->access_role, self::DELETE_ROLES, true)) {
            return ['success' => false, 'error' => '계약 삭제 권한이 없습니다.'];
        }

        $row = ProjectContract::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }

        // 서류가 붙어 있으면 지우지 않는다. 서명본과 보험증서가 함께 사라진다.
        $docs = $row->documents()->count();
        if ($docs > 0) {
            return ['success' => false, 'error' => "붙어 있는 서류가 {$docs}건 있습니다. 서류를 먼저 정리하거나 상태를 \"해지\" 로 두세요."];
        }

        $row->delete();

        return ['success' => true];
    }

    // ── 서류 ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function documents(int $contractId): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '계약 조회 권한이 없습니다.'];
        }

        $contract = ProjectContract::find($contractId);
        if (! $contract) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }

        $today = Carbon::now()->toDateString();

        $rows = ProjectContractDocument::query()
            ->where('project_contract_id', $contractId)
            ->with(['uploadedBy:id,name'])
            ->orderByDesc('is_current')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ProjectContractDocument $d): array => [
                'id' => $d->id,
                'title' => $d->title,
                'documentType' => $d->document_type,
                'documentTypeLabel' => ProjectContractDocument::TYPE_OPTIONS[$d->document_type] ?? (string) $d->document_type,
                'documentNumber' => $d->document_number,
                'version' => $d->version,
                'status' => $d->status,
                'statusLabel' => ProjectContractDocument::STATUS_OPTIONS[$d->status] ?? (string) $d->status,
                'isCurrent' => (bool) $d->is_current,
                'isRequired' => (bool) $d->is_required,
                'isConfidential' => (bool) $d->is_confidential,
                'issuedOn' => $d->issued_on?->toDateString(),
                'effectiveOn' => $d->effective_on?->toDateString(),
                'expiresOn' => $d->expires_on?->toDateString(),
                'expired' => $d->expires_on && $d->expires_on->toDateString() < $today,
                'fileName' => $d->original_file_name,
                'fileSize' => $d->file_size,
                'uploadedBy' => $d->uploadedBy?->name,
                'uploadedAt' => $d->created_at?->toDateTimeString(),
                'downloadUrl' => route('project-contract-document.download', ['document' => $d->id]),
                'notes' => $d->notes,
            ])->values()->all();

        return ['success' => true, 'rows' => $rows, 'canManage' => $this->canManage(), 'contractTitle' => $contract->title];
    }

    /**
     * 서류 업로드. 같은 종류의 최신본이 있으면 그것을 "대체됨" 으로 내리고
     * 새 것을 현재로 세운다 — 어느 것이 최신인지 모르는 서류철이 가장 위험하다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function uploadDocument(int $contractId, ?UploadedFile $file, array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '서류 등록 권한이 없습니다.'];
        }

        $contract = ProjectContract::find($contractId);
        if (! $contract) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }
        if (! $file) {
            return ['success' => false, 'errors' => ['file' => '파일을 올리세요.']];
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            return ['success' => false, 'errors' => ['file' => '파일이 너무 큽니다. '.round(self::MAX_KB / 1024).'MB 이하로 올려 주세요.']];
        }

        $docType = (string) ($input['documentType'] ?? 'other');
        if (! array_key_exists($docType, ProjectContractDocument::TYPE_OPTIONS)) {
            return ['success' => false, 'errors' => ['documentType' => '서류 종류를 선택하세요.']];
        }
        $status = (string) ($input['status'] ?? 'draft');
        if (! array_key_exists($status, ProjectContractDocument::STATUS_OPTIONS)) {
            $status = 'draft';
        }

        $disk = $this->disk();
        $path = $file->store('contract-documents/'.$contractId, $disk);

        $previous = ProjectContractDocument::query()
            ->where('project_contract_id', $contractId)
            ->where('document_type', $docType)
            ->where('is_current', true)
            ->latest('id')
            ->first();

        $doc = ProjectContractDocument::create([
            'project_contract_id' => $contractId,
            'replaces_document_id' => $previous?->id,
            'uploaded_by' => auth()->id(),
            'document_type' => $docType,
            'title' => trim((string) ($input['title'] ?? '')) ?: $file->getClientOriginalName(),
            'document_number' => trim((string) ($input['documentNumber'] ?? '')) ?: null,
            'version' => $previous ? ((int) $previous->version + 1) : 1,
            'status' => $status,
            'is_required' => (bool) ($input['isRequired'] ?? false),
            'is_current' => true,
            'is_confidential' => (bool) ($input['isConfidential'] ?? false),
            'issued_on' => $this->date($input['issuedOn'] ?? null),
            'effective_on' => $this->date($input['effectiveOn'] ?? null),
            'expires_on' => $this->date($input['expiresOn'] ?? null),
            'disk' => $disk,
            'file_path' => $path,
            'original_file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ]);

        if ($previous) {
            $previous->update(['is_current' => false, 'status' => 'superseded']);
        }

        return ['success' => true, 'id' => $doc->id, 'version' => $doc->version, 'replaced' => (bool) $previous];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteDocument(int $id): array
    {
        $actor = auth()->user();
        if (! $actor || $actor->account_status !== 'active' || ! in_array($actor->access_role, self::DELETE_ROLES, true)) {
            return ['success' => false, 'error' => '서류 삭제 권한이 없습니다.'];
        }

        $doc = ProjectContractDocument::find($id);
        if (! $doc) {
            return ['success' => false, 'error' => '서류를 찾을 수 없습니다.'];
        }

        // 최신본을 지우면 바로 앞 버전을 다시 현재로 올린다. 안 그러면 그 종류의
        // 현재 서류가 없는 상태가 되어 "최신본이 뭐지" 를 알 수 없게 된다.
        if ($doc->is_current && $doc->replaces_document_id) {
            ProjectContractDocument::where('id', $doc->replaces_document_id)
                ->update(['is_current' => true, 'status' => 'approved']);
        }

        try {
            Storage::disk($doc->disk ?: $this->disk())->delete($doc->file_path);
        } catch (\Throwable $e) {
            report($e);   // 파일이 이미 없어도 레코드는 지운다
        }

        $doc->delete();

        return ['success' => true];
    }

    private function date(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'payroll'], true) || $user->access_scope === 'all_sites') {
            return;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            $query->where('company_id', $user->allowed_company_id);

            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}
