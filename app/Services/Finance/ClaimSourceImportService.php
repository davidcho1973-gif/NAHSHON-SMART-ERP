<?php

namespace App\Services\Finance;

use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\ProjectContract;
use App\Services\Admin\BillingAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** An imported claim is a source assertion, never verified work or a cash posting. */
class ClaimSourceImportService
{
    public function import(int $contractId, array $source, int $workbookId, int $drawingId): array
    {
        $billing = app(BillingAdminService::class);
        if (! $billing->canManage()) {
            return ['success' => false, 'error' => '기성 근거 관리 권한이 없습니다.'];
        }
        $contract = $billing->findAccessibleContract($contractId);
        if (! $contract || $contract->direction !== 'receivable') {
            return ['success' => false, 'error' => '접근 가능한 수주 계약을 찾을 수 없습니다.'];
        }
        $validator = Validator::make($source, [
            'schema_version' => 'required|in:1.0',
            'data_classification' => 'required|in:source_claims_not_verified_actuals',
            'project.application_date' => 'required|date_format:Y-m-d',
            'project.claim_period_start' => 'required|date_format:Y-m-d',
            'project.claim_period_end' => 'required|date_format:Y-m-d|after_or_equal:project.claim_period_start',
            'project.currency' => 'required|string|size:3',
            'sources.workbook' => 'required|string|max:255',
            'sources.drawing_pdf' => 'required|string|max:255',
            'sources.workbook_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'sources.drawing_pdf_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'sources.pdf_page_count' => 'required|integer|min:1|max:10000',
            'totals.contract' => 'required|numeric|min:0|max:999999999999',
            'totals.current_claim' => 'required|numeric|min:0|max:999999999999',
            'totals.advance' => 'required|numeric|min:0|max:999999999999',
            'totals.retention' => 'required|numeric|min:0|max:999999999999',
            'totals.current_due' => 'required|numeric|min:0|max:999999999999',
            'totals.rounding_adjustment' => 'nullable|numeric|min:-999999999999|max:999999999999',
            'items' => 'required|array|min:1|max:2000',
            'items.*.boq' => 'required|integer|min:1|distinct',
            'items.*.description' => 'required|string|max:4000',
            'items.*.unit' => 'required|string|max:20',
            'items.*.contract_qty' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.current_qty' => 'required|numeric|gt:0',
            'items.*.current' => 'required|numeric|min:0',
            'items.*.row' => 'required|integer|min:1|max:1048576',
            'items.*.pdf_pages' => 'present|array|max:20',
            'items.*.pdf_pages.*' => 'integer|min:1|max:'.(int) data_get($source, 'sources.pdf_page_count', 1),
        ]);
        if ($validator->fails()) {
            return ['success' => false, 'error' => $validator->errors()->first()];
        }
        try {
            foreach ([$workbookId => 'workbook', $drawingId => 'drawing_pdf'] as $id => $kind) {
                $doc = IntelligentDocument::visibleTo(auth()->user())->find($id);
                if (! $doc || $doc->access_level === 'private'
                    || ! hash_equals(strtolower((string) $doc->sha256), $source['sources'][$kind.'_sha256'])) {
                    throw new InvalidArgumentException('선택한 원본 문서의 SHA256이 가져오기 자료와 일치하지 않거나 공유 문서가 아닙니다.');
                }
                $same = $doc->project_contract_id ? (int) $doc->project_contract_id === $contract->id
                    : ($contract->project_id ? (int) $doc->project_id === (int) $contract->project_id
                        : ($contract->site_id && (int) $doc->site_id === (int) $contract->site_id));
                if (! $same || ($doc->company_id && $contract->company_id && (int) $doc->company_id !== (int) $contract->company_id)) {
                    throw new InvalidArgumentException('다른 계약·프로젝트의 원본은 가져올 수 없습니다.');
                }
            }
            if ($workbookId === $drawingId) {
                throw new InvalidArgumentException('엑셀과 도면 원본은 서로 다른 문서여야 합니다.');
            }
            if (strtoupper($source['project']['currency']) !== strtoupper($contract->currency)
                || ($contract->current_amount !== null && abs((float) $contract->current_amount - (float) $source['totals']['contract']) >= 0.01)) {
                throw new InvalidArgumentException('선택한 계약의 통화·계약액과 원본 청구자료가 다릅니다. 계약 연결을 확인하세요.');
            }

            return DB::transaction(function () use ($contract, $source, $workbookId, $drawingId): array {
                $contract = ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
                $key = $source['sources']['workbook_sha256'];
                $payload = $contract->payload ?? [];
                if (isset($payload['claimSourceImports'][$key])) {
                    return ['success' => true, 'imported' => 0, 'duplicate' => true, 'message' => '이미 가져온 원본입니다. 기존 수량·검토 기록을 보존했습니다.'];
                }
                $evidence = app(ClaimEvidenceService::class);
                foreach ($source['items'] as $item) {
                    $lineNo = (string) $item['boq'];
                    $ref = 'source:'.$key.':'.$lineNo;
                    $locator = '3_CONTINUATION SHEET!A'.$item['row'].':T'.$item['row'];
                    $line = ContractBoqLine::where('project_contract_id', $contract->id)->where('line_no', $lineNo)->first();
                    if ($line) {
                        throw new InvalidArgumentException('BOQ '.$lineNo.'이 이미 있습니다. 원본 가져오기로 기존 계약 항목을 덮어쓰지 않습니다.');
                    }
                    $fabrication = ($item['claim_basis'] ?? '') === 'fabrication_only'
                        || ($item['group_id'] ?? '') === 'panel-fabrication';
                    $saved = $evidence->saveLine([
                        'projectContractId' => $contract->id, 'lineNo' => $lineNo,
                        'description' => $item['description'].(! empty($item['spec']) ? ' · '.mb_substr((string) $item['spec'], 0, 4000) : ''),
                        'unit' => $item['unit'], 'contractQty' => $item['contract_qty'], 'unitPrice' => $item['unit_price'],
                        'recognitionBasis' => $fabrication ? 'milestone' : 'quantity', 'stageWeights' => [],
                        'status' => 'draft', 'sourceDocumentId' => $workbookId, 'sourceLocator' => $locator, 'sourceRef' => $ref,
                    ]);
                    $this->requireSuccess($saved);
                    $links = [['type' => 'document', 'id' => $workbookId, 'locator' => $locator, 'note' => '원문 청구값. 검측 실적 아님.']];
                    foreach ($item['pdf_pages'] as $page) {
                        $links[] = ['type' => 'document', 'id' => $drawingId, 'locator' => 'PDF p.'.$page, 'note' => '청구 마크업. 현장사진·검측 확인 필요.'];
                    }
                    $note = '원본 청구기간 '.$source['project']['claim_period_start'].' ~ '.$source['project']['claim_period_end']
                        .' · 원본 기준 '.mb_substr((string) ($source['project']['period_basis'] ?? '미확인'), 0, 80)
                        .' · 원본 항목 청구액 '.$item['current'].' '.$source['project']['currency']
                        .' · 기록일은 청구서 작성일이며 실제 시공일이 아닙니다.'
                        .($fabrication ? ' 제작 청구이며 설치 실적이 아닙니다. 계약 단계별 지급 조건 확인 필요.' : '')
                        .($item['pdf_pages'] === [] ? ' 도면 마크업의 명시 항목과 연결되지 않음: 추가 근거 확인 필요.' : '')
                        .' · 사진 ID는 원문 참조일 뿐 실제 사진 파일은 제공되지 않았습니다.';
                    $this->requireSuccess($evidence->saveRecord([
                        'lineId' => $saved['id'], 'recordKind' => 'source_claim',
                        'workDate' => $source['project']['application_date'], 'location' => '원본 청구자료 · 세부 작업위치 확인 필요',
                        'stage' => $fabrication ? 'fabrication' : 'installed', 'reportedQty' => $item['current_qty'],
                        'sourceRef' => $ref, 'notes' => $note, 'evidence' => $links,
                    ]));
                }
                // The source summary is provenance only. It never touches PayApplication or receipts.
                $payload['claimSourceImports'][$key] = [
                    'workbookDocumentId' => $workbookId, 'drawingDocumentId' => $drawingId,
                    'periodStart' => $source['project']['claim_period_start'], 'periodEnd' => $source['project']['claim_period_end'],
                    'applicationDate' => $source['project']['application_date'], 'periodBasis' => mb_substr((string) ($source['project']['period_basis'] ?? ''), 0, 80),
                    'totals' => array_intersect_key($source['totals'], array_flip(['contract', 'current_claim', 'advance', 'retention', 'current_due', 'rounding_adjustment'])),
                    'rowCount' => count($source['items']), 'importedBy' => auth()->id(), 'importedAt' => now()->toIso8601String(),
                    'notice' => '청구된 항목만 가져온 부분 대장입니다. 계약 전체 BOQ·실적·승인·입금이 확인된 자료가 아닙니다.',
                ];
                $contract->update(['payload' => $payload]);

                return ['success' => true, 'imported' => count($source['items']), 'duplicate' => false, 'message' => '원본 청구자료를 확인 대기로 가져왔습니다. 기성·수금 원장에는 반영하지 않았습니다.'];
            });
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function requireSuccess(array $result): void
    {
        if (! ($result['success'] ?? false)) {
            throw new InvalidArgumentException($result['error'] ?? '원본 행을 가져오지 못했습니다.');
        }
    }
}
