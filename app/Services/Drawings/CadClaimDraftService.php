<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\ClaimEditDraft;
use App\Models\ContractBoqLine;
use App\Services\Finance\ClaimEditingService;
use App\Services\Finance\ClaimEvidenceService;
use App\Support\JobAccess;
use Illuminate\Support\Facades\DB;

class CadClaimDraftService
{
    public function __construct(private readonly ClaimEvidenceService $ledger, private readonly ClaimEditingService $editing, private readonly CadDrawingService $cad) {}

    public function context(int $lineId): array
    {
        $this->cad->gate(auth()->user());
        $line = ContractBoqLine::with('contract')->findOrFail($lineId);
        $ledger = $this->ledger->getLedger($line->project_contract_id);
        abort_unless($ledger['success'] ?? false, 403, $ledger['error'] ?? '계약 접근 권한이 없습니다.');
        $draft = ClaimEditDraft::where('contract_boq_line_id', $lineId)->where('user_id', auth()->id())->first();

        return ['line' => ['id' => $lineId, 'contractId' => $line->project_contract_id, 'siteId' => $line->contract->site_id, 'description' => $line->description],
            'draftRevision' => (int) ($draft?->revision ?? 0), 'recordId' => $draft?->claim_work_record_id,
            'canManage' => ($ledger['canManage'] ?? false) && $this->cad->canManage(auth()->user()) && ! $draft?->claim_work_record_id
                && (! JobAccess::managed(auth()->user()) || JobAccess::can(auth()->user(), 'documents', 'export'))];
    }

    public function attach(CadDrawing $drawing, int $lineId, int $drawingRevision, int $draftRevision): array
    {
        $this->cad->access(auth()->user(), $drawing, true, true);
        $context = $this->context($lineId);
        abort_unless($context['canManage'], 403, '편집 가능한 개인 기성 초안이 필요합니다.');
        $line = ContractBoqLine::with('contract')->findOrFail($lineId);
        abort_unless((int) $drawing->site_id === (int) $line->contract->site_id && (int) $drawing->company_id === (int) $line->contract->company_id, 404);
        $revision = $drawing->revisions()->where('revision', $drawingRevision)->firstOrFail();
        $snapshot = $this->cad->read($drawing, $drawingRevision)['dxf'];
        abort_unless(hash_equals($revision->sha256, hash('sha256', $snapshot)), 409, 'CAD 개정본 파일의 해시가 변경되었습니다.');
        $proof = $this->ledger->editableReferences($line->project_contract_id, [['type' => 'cad_revision', 'id' => $revision->id, 'locator' => 'CAD r'.$drawingRevision]])[0];

        return DB::transaction(function () use ($line, $proof, $draftRevision): array {
            // The same parent lock as ordinary draft saves makes the external CAD editor an optimistic writer.
            ContractBoqLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $draft = ClaimEditDraft::where('contract_boq_line_id', $line->id)->where('user_id', auth()->id())->lockForUpdate()->first();
            abort_if((int) ($draft?->revision ?? 0) !== $draftRevision, 409, '기성 초안이 변경되었습니다. 다시 열어 확인하세요.');
            abort_if($draft?->claim_work_record_id, 409, '이미 작업 기록으로 전환한 초안은 수정할 수 없습니다.');
            $content = $draft?->content ?? [];
            foreach ($content['evidence'] ?? [] as $existing) {
                if (($existing['type'] ?? '') === 'cad_revision' && (int) $existing['id'] === (int) $proof['id']) {
                    abort_unless(($existing['sha256'] ?? null) === $proof['sha256'], 409, '연결된 CAD 원본 해시가 변경되었습니다.');

                    return ['success' => true, 'draft' => $this->row($draft), 'evidence' => $proof, 'alreadyAttached' => true];
                }
            }
            $content['evidence'][] = $proof;
            $saved = $this->editing->save(['lineId' => $line->id, 'revision' => $draftRevision, 'content' => $content]);
            abort_unless($saved['success'] ?? false, 422, $saved['error'] ?? 'CAD 근거를 초안에 연결하지 못했습니다.');

            return $saved + ['evidence' => $proof];
        });
    }

    private function row(ClaimEditDraft $draft): array
    {
        return ['id' => $draft->id, 'lineId' => $draft->contract_boq_line_id, 'revision' => $draft->revision,
            'content' => $draft->content, 'history' => $draft->history ?? [], 'recordId' => $draft->claim_work_record_id];
    }
}
