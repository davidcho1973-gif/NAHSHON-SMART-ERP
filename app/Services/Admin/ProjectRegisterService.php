<?php

namespace App\Services\Admin;

use App\Models\BoqItem;
use App\Models\IntelligentDocument;
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

        $q = Submittal::query()
            ->with(['sourceDocument:id,title,original_file_name,extension', 'documents:id,title,original_file_name'])
            ->orderBy('seq');
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
            // 어디서 나온 조항인지 — 추출할 때 이미 기록해 둔 것을 화면까지 내보낸다.
            // 예전에는 DB 에만 있고 화면에는 없어서, 조항을 보고도 원문을 찾으려면
            // 문서함을 따로 열어 파일명을 기억으로 뒤져야 했다.
            'sourceDocumentId' => $s->source_document_id,
            'sourceDocument' => $s->sourceDocument?->title ?: $s->sourceDocument?->original_file_name,
            'sourceExcerpt' => $s->source_excerpt,
            'confidence' => $s->confidence,
            'needsReview' => (bool) $s->needs_review,
            'reviewReason' => $s->review_reason,
            'extractedBy' => $s->extracted_by,
            // 이 조항을 채우려고 받아 둔 자료들 — 대장에서 바로 열린다.
            'documents' => $s->documents->map(fn (IntelligentDocument $d): array => [
                'id' => $d->id,
                'label' => $d->title ?: $d->original_file_name,
            ])->values()->all(),
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

    /**
     * 제출물 조항으로 업체 자료 요청서를 만들어 문서함에 넣는다.
     *
     * 제품자료·제작도는 AI 가 만들 수 없다(제조사가 발급한다). 대신 "이 조항 때문에
     * 이런 자료가 필요하다" 고 업체에 요청하는 편지는 지금 사람이 조항을 읽고 매번
     * 손으로 쓴다 — 276건이면 276번이다. 그 편지를 대신 쓴다.
     *
     * @return array<string, mixed>
     */
    public function requestVendorData(int $submittalId, ?string $vendorName = null): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '제출물 대장 수정 권한이 없습니다.'];
        }

        $row = Submittal::query()->find($submittalId);
        if (! $row) {
            return ['success' => false, 'error' => '해당 항목이 없습니다.'];
        }

        $result = app(\App\Services\Takeoff\SubmittalRequestService::class)
            ->build($row, $vendorName !== null && trim($vendorName) !== '' ? trim($vendorName) : null);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        return [
            'success' => true,
            'documentId' => $result['documentId'],
            'message' => "요청서를 만들어 문서함에 넣었습니다 — 요청 항목 {$result['items']}개. 문서함에서 열어 확인·발송하세요.",
        ];
    }

    /**
     * 제품 제출물 자료를 AI 웹 조사로 찾는다 — 후보만 돌려주고, 편철은 사람이 고른 뒤에.
     *
     * @return array<string, mixed>
     */
    public function researchSubmittal(int $submittalId): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '제출물 대장 수정 권한이 없습니다.'];
        }

        $row = Submittal::query()->find($submittalId);
        if (! $row) {
            return ['success' => false, 'error' => '해당 항목이 없습니다.'];
        }

        return app(\App\Services\Takeoff\SubmittalResearchService::class)->research($row);
    }

    /**
     * 조사 후보 하나를 받아 문서함에 편철하고 제출물에 연결한다.
     *
     * @return array<string, mixed>
     */
    public function fileSubmittalResearch(int $submittalId, int $index): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '제출물 대장 수정 권한이 없습니다.'];
        }

        $row = Submittal::query()->find($submittalId);
        if (! $row) {
            return ['success' => false, 'error' => '해당 항목이 없습니다.'];
        }

        return app(\App\Services\Takeoff\SubmittalResearchService::class)
            ->fileCandidate($row, $index, auth()->id());
    }

    /**
     * 대장의 한 줄이 가리키는 원본 문서를 ERP 안에서 열기 위한 정보.
     *
     * 대장에서 조항을 눌렀는데 파일명만 알려 주고 끝나면, 사람은 문서함을 새로 열어
     * 그 이름을 다시 찾아야 한다. 그러면 대장과 근거가 두 화면으로 갈라진다 —
     * 여기서 미리보기 주소까지 돌려주어 같은 화면 위에서 원문을 편다.
     *
     * @return array<string, mixed>
     */
    public function sourceDocument(int $documentId): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '원본 문서 열람 권한이 없습니다.'];
        }

        $document = IntelligentDocument::query()
            ->visibleTo(auth()->user())
            ->whereKey($documentId)
            ->first();

        if (! $document) {
            return ['success' => false, 'error' => '원본 문서를 찾을 수 없거나 열람 범위 밖입니다.'];
        }

        return [
            'success' => true,
            'id' => $document->id,
            'title' => $document->displayTitle(),
            'fileName' => $document->original_file_name,
            'extension' => strtolower((string) $document->extension),
            'category' => $document->category,
            'documentNumber' => $document->document_number,
            'revision' => $document->revision,
            'summary' => $document->summary,
            'previewUrl' => route('document-intelligence.preview', $document),
            'downloadUrl' => route('document-intelligence.download', $document),
            'hubUrl' => '/document-hub?q='.urlencode((string) $document->original_file_name),
        ];
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

        $q = BoqItem::query()->with('sourceDocument:id,title,original_file_name,extension')->orderBy('seq');
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
            'sourceDocument' => $b->sourceDocument?->title ?: $b->sourceDocument?->original_file_name,
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
