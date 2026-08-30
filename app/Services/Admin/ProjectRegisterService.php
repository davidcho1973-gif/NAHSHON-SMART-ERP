<?php

namespace App\Services\Admin;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * 제출물 대장 + 물량/BOQ — 시방·도면에서 뽑은 계약 요구를 프로젝트 단위로 추적한다.
 *
 * 두 화면이 같은 스코프(프로젝트 선택)와 같은 권한 축을 쓰므로 한 서비스로 묶었다.
 * 행의 "내용"(조항·물량 근거)은 임포트가 정본이고, 화면에서는 진행 상태·담당·날짜·
 * 수량·단가만 고친다 — 근거(source/qty_basis)는 화면에서 못 지운다.
 */
class ProjectRegisterService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'payroll', 'site_manager', 'safety_manager'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager'];

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

    /** @return array<string, mixed> */
    public function listSubmittals(?int $projectId = null, string $siteId = 'ALL'): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '제출물 대장 열람 권한이 없습니다.'];
        }

        $siteKey = $this->siteKey($siteId);
        $projects = $this->projectOptions($siteKey);
        $projectId = $this->resolveProject($projectId, $projects);

        $q = Submittal::query()->orderBy('seq');
        $this->applyScope($q);

        // 물량 대장과 같은 규칙 — 고른 현장에 프로젝트가 없으면 남의 것을 보여 주지 않는다.
        if ($siteKey !== null && ! $projectId) {
            $q->whereRaw('1 = 0');
        }

        if ($projectId) {
            $q->where('project_id', $projectId);
        }

        $rows = $q->get()->map(fn (Submittal $s): array => [
            'id' => $s->id,
            'seq' => $s->seq,
            'csi' => $s->csi,
            'section' => $s->section,
            'category' => $s->category,
            'title' => $s->title,
            'gate' => $s->gate,
            'status' => $s->status,
            'assignee' => $s->assignee,
            'plannedOn' => $s->planned_on?->format('Y-m-d'),
            'submittedOn' => $s->submitted_on?->format('Y-m-d'),
            'approvedOn' => $s->approved_on?->format('Y-m-d'),
            'notes' => $s->notes,
        ])->values()->all();

        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
        }

        return [
            'canManage' => $this->canManage(),
            'projects' => $projects,
            'projectId' => $projectId,
            'statuses' => array_keys(Submittal::STATUS_OPTIONS),
            'rows' => $rows,
            'stats' => [
                'total' => count($rows),
                'gate' => count(array_filter($rows, fn ($r) => $r['gate'])),
                'byStatus' => $byStatus,
            ],
        ];
    }

    /**
     * 상태·담당·날짜·메모만 갱신한다 — 조항 내용(title/csi/category)은 임포트가 정본.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveSubmittal(array $data): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '제출물 대장 수정 권한이 없습니다.'];
        }

        $row = Submittal::query()->find((int) ($data['id'] ?? 0));
        if (! $row) {
            return ['success' => false, 'error' => '해당 항목이 없습니다.'];
        }

        $status = (string) ($data['status'] ?? $row->status);
        if (! array_key_exists($status, Submittal::STATUS_OPTIONS)) {
            return ['success' => false, 'error' => '알 수 없는 상태값입니다: '.$status];
        }

        $row->fill([
            'status' => $status,
            'assignee' => trim((string) ($data['assignee'] ?? '')) ?: null,
            'planned_on' => ($data['plannedOn'] ?? null) ?: null,
            'submitted_on' => ($data['submittedOn'] ?? null) ?: null,
            'approved_on' => ($data['approvedOn'] ?? null) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        return ['success' => true, 'id' => $row->id, 'status' => $row->status];
    }

    /** @return array<string, mixed> */
    public function listBoq(?int $projectId = null, string $siteId = 'ALL'): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '물량/BOQ 열람 권한이 없습니다.'];
        }

        $siteKey = $this->siteKey($siteId);
        $projects = $this->projectOptions($siteKey);
        $projectId = $this->resolveProject($projectId, $projects);

        $q = BoqItem::query()->orderBy('seq');
        $this->applyScope($q);

        // 현장을 골랐는데 그 현장에 프로젝트가 없으면 <b>비어 있는 것이 맞다</b>.
        // 예전에는 여기서 조건 없이 전체 대장을 훑어, 남의 현장 물량이 그대로 떴다.
        if ($siteKey !== null && ! $projectId) {
            $q->whereRaw('1 = 0');
        }

        if ($projectId) {
            $q->where('project_id', $projectId);
        }

        $rows = $q->get()->map(fn (BoqItem $b): array => [
            'id' => $b->id,
            'seq' => $b->seq,
            'disciplineCode' => $b->discipline_code,
            'discipline' => $b->discipline,
            'nameKr' => $b->name_kr,
            'nameEn' => $b->name_en,
            'spec' => $b->spec,
            'unit' => $b->unit,
            'qty' => (float) $b->qty,
            'qtyBasis' => $b->qty_basis,
            'unitPrice' => (float) $b->unit_price,
            'amount' => (float) $b->amount,
            'source' => $b->source,
            'note' => $b->note,
            'flagged' => $b->flagged,
            'wbsActivityId' => $b->wbs_activity_id,
            // 도면 판독으로 들어온 줄 — 애매한 것만 사람이 본다.
            'confidence' => $b->confidence,
            'needsReview' => (bool) $b->needs_review,
            'reviewReason' => $b->review_reason,
            'sourceDocumentId' => $b->source_document_id,
            'extractedBy' => $b->extracted_by,
        ])->values()->all();

        $byDiscipline = [];
        foreach ($rows as $r) {
            $key = $r['disciplineCode'];
            $byDiscipline[$key] ??= ['code' => $key, 'name' => $r['discipline'], 'count' => 0, 'amount' => 0.0];
            $byDiscipline[$key]['count']++;
            $byDiscipline[$key]['amount'] += $r['amount'];
        }
        ksort($byDiscipline);

        return [
            'canManage' => $this->canManage(),
            'projects' => $projects,
            'projectId' => $projectId,
            'rows' => $rows,
            'totals' => [
                'grand' => array_sum(array_column($rows, 'amount')),
                'byDiscipline' => array_values($byDiscipline),
                'unresolved' => count(array_filter($rows, fn ($r) => $r['qtyBasis'] === '미확정')),
                'flagged' => count(array_filter($rows, fn ($r) => $r['flagged'])),
                // 도면 판독이 자신 없어 한 줄 — 여기만 사람이 보면 된다.
                'needsReview' => count(array_filter($rows, fn ($r) => $r['needsReview'])),
            ],
        ];
    }

    /**
     * 수량·수량근거·단가·메모만 갱신 — 금액은 모델이 재계산한다.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveBoqItem(array $data): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '물량/BOQ 수정 권한이 없습니다.'];
        }

        $row = BoqItem::query()->find((int) ($data['id'] ?? 0));
        if (! $row) {
            return ['success' => false, 'error' => '해당 항목이 없습니다.'];
        }

        $basis = (string) ($data['qtyBasis'] ?? $row->qty_basis);
        if (! array_key_exists($basis, BoqItem::QTY_BASIS_OPTIONS)) {
            return ['success' => false, 'error' => '알 수 없는 수량근거입니다: '.$basis];
        }

        $qty = (float) ($data['qty'] ?? $row->qty);
        $price = (float) ($data['unitPrice'] ?? $row->unit_price);
        if ($qty < 0 || $price < 0) {
            return ['success' => false, 'error' => '수량·단가는 음수가 될 수 없습니다.'];
        }

        $row->fill([
            'qty' => $qty,
            'qty_basis' => $basis,
            'unit_price' => $price,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'wbs_activity_id' => strtoupper(trim((string) ($data['wbsActivityId'] ?? ''))) ?: null,
        ])->save();

        return ['success' => true, 'id' => $row->id, 'amount' => (float) $row->amount];
    }

    /** @return list<array{id:int, label:string}> */
    /**
     * 고를 수 있는 프로젝트 — 현장을 골랐으면 그 현장 것만.
     *
     * 예전에는 현장을 전혀 보지 않았다. 그래서 애리조나 현장을 띄워 놓고도 프로젝트가
     * 지정되지 않으면 전체에서 첫 번째(코드순)로 넘어가, 조지아 현장의 물량 대장이
     * 애리조나 화면에 그대로 떴다. 대장은 현장의 것이지 회사 전체의 것이 아니다.
     *
     * @return list<array{id:int, label:string}>
     */
    private function projectOptions(?int $siteId = null): array
    {
        $q = Project::query()->orderBy('project_code');
        $this->applyScope($q);
        if ($siteId !== null) {
            $q->where('site_id', $siteId);
        }

        return $q->get(['id', 'project_code', 'name'])
            ->map(fn (Project $p): array => ['id' => $p->id, 'label' => $p->project_code.' — '.$p->name])
            ->values()->all();
    }

    /** 화면이 고른 현장 코드를 id 로. 'ALL'·빈 값이면 null(전체). */
    private function siteKey(string $siteId): ?int
    {
        $siteId = trim($siteId);

        return ($siteId === '' || $siteId === 'ALL')
            ? null
            : Site::query()->where('code', $siteId)->value('id');
    }

    /** @param list<array{id:int, label:string}> $projects */
    private function resolveProject(?int $projectId, array $projects): ?int
    {
        $ids = array_column($projects, 'id');
        if ($projectId && in_array($projectId, $ids, true)) {
            return $projectId;
        }

        return $ids[0] ?? null;
    }

    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return;
        }

        $companyId = CurrentCompany::id() ?? ($user->allowed_company_id ?: $user->employee?->company_id);
        if ($companyId) {
            $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
        }
    }
}
