<?php

namespace App\Services\Finance;

use App\Models\ContractBoqLine;
use App\Models\DrawingSheet;
use App\Models\ProjectContract;
use App\Models\WorkSection;
use App\Services\Drawings\SectionDrawingService;
use App\Support\JobAccess;

/** Read the original associations; a work record is not required to see its supporting documents. */
class ClaimLineWorkspaceService
{
    public function get(int $contractId, int $lineId, int $page = 1): array
    {
        $inbox = app(ClaimEvidenceInboxService::class)->getInbox($contractId, ['lineId' => $lineId, 'status' => 'all', 'page' => $page, 'perPage' => 25]);
        if (! ($inbox['success'] ?? false)) {
            return $inbox;
        }
        $line = ContractBoqLine::where('project_contract_id', $contractId)->find($lineId);
        $contract = ProjectContract::findOrFail($contractId);
        $section = WorkSection::where('project_contract_id', $contractId)->where('site_id', $contract->site_id)->with('sheets')->find($line?->work_section_id);
        $drawings = [];
        $service = app(SectionDrawingService::class);
        $canViewDrawings = $service->canView(auth()->user()) && (! JobAccess::managed(auth()->user()) || (JobAccess::can(auth()->user(), 'progress') && JobAccess::can(auth()->user(), 'documents')));
        if ($section && $canViewDrawings) {
            foreach ($section->sheets as $pick) {
                $sheet = DrawingSheet::where('site_id', $contract->site_id)
                    ->whereRaw('upper(sheet_no) = ?', [DrawingSheet::normalizeNo($pick->sheet_no)])
                    ->whereHas('document', fn ($q) => $q->visibleTo(auth()->user()))
                    ->with('document')->orderByDesc('intelligent_document_id')->orderByDesc('id')->first();
                if ($sheet && $service->findDocument(auth()->user(), $sheet->intelligent_document_id)) {
                    $drawings[] = $service->sheetRow($sheet);
                }
            }
        }

        return ['success' => true, 'siteId' => $contract->site_id, 'projectId' => $contract->project_id, 'companyId' => $contract->company_id, 'sectionId' => $section?->id,
            'drawings' => $drawings, 'evidence' => $inbox];
    }
}
