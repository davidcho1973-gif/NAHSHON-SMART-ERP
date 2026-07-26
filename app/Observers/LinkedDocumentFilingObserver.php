<?php

namespace App\Observers;

use App\Models\ProcurementItem;
use App\Models\ProjectContractDocument;
use App\Services\IntegratedDocumentService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 조달(발주)·계약 첨부서류가 등록되면 문서통합관리로 자동 편철한다.
 *
 * 편철 실패가 원래 업무(발주·계약 등록)를 막으면 안 되므로 예외는 삼키고 로그만 남긴다.
 */
class LinkedDocumentFilingObserver
{
    public function procurementSaved(ProcurementItem $item): void
    {
        // 첨부 파일이 새로 붙었을 때만 편철한다(신규 생성 포함).
        if (blank($item->document_path) || ! ($item->wasRecentlyCreated || $item->wasChanged('document_path'))) {
            return;
        }
        $this->guard(fn () => app(IntegratedDocumentService::class)->fileProcurementDocument($item), 'procurement', $item->id);
    }

    public function contractDocumentSaved(ProjectContractDocument $doc): void
    {
        if (blank($doc->file_path) || ! ($doc->wasRecentlyCreated || $doc->wasChanged('file_path'))) {
            return;
        }
        $this->guard(fn () => app(IntegratedDocumentService::class)->fileContractDocument($doc), 'contract', $doc->id);
    }

    private function guard(callable $fn, string $kind, int|string $id): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning(sprintf('%s 문서 자동 편철 실패(원 업무는 정상): %s', $kind, $e->getMessage()), ['id' => $id]);
        }
    }
}
