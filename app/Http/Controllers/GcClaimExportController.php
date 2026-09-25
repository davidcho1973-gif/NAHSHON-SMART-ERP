<?php

namespace App\Http\Controllers;

use App\Services\Finance\GcClaimWorkbookService;
use Symfony\Component\HttpFoundation\Response;

/** 원청 청구서 엑셀 내려받기 — 만드는 규칙은 GcClaimWorkbookService 한 곳에 있다. */
class GcClaimExportController extends Controller
{
    public function download(int $application, GcClaimWorkbookService $workbooks): Response
    {
        $built = $workbooks->build($application);
        if (! ($built['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $built['error'] ?? '만들지 못했습니다.'], 422);
        }

        return response()->download($built['path'], $built['fileName'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
