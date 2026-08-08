<?php

namespace App\Http\Controllers;

use App\Models\IntegratedDocument;
use App\Models\ProcurementItem;
use App\Services\Procurement\ProcurementDocAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 조달 서류 AI 분석 — 발주서/선적서 등을 업로드하면 벤더·PO·금액·ETA 를 뽑고 단계를 자동 판정한다.
 * (조달 항목의 조회/저장 JSON API 는 SmartCompanyData 의 api_getProcurement/api_updateProcurement 로 처리.)
 */
class ProcurementController extends Controller
{
    public function __construct(private readonly ProcurementDocAnalyzer $analyzer)
    {
    }

    /**
     * 서류 업로드 → AI 분석 → 추출값 + 제안 단계 반환(+원본 보관). 저장은 사용자가 확인 후 별도로.
     */
    public function analyze(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:32768',
        ]);

        try {
            $file = $request->file('file');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'], true)) {
                return response()->json([
                    'success' => false,
                    'error' => sprintf('지원하지 않는 형식입니다(.%s). 발주서는 PDF·이미지·docx·xlsx 로 올려주세요.', $ext ?: '?'),
                ], 422);
            }

            $mime = $file->getClientMimeType() ?: ($file->getMimeType() ?: 'application/octet-stream');

            // 업로드된 임시 파일을 바로 분석(빠른 왕복), 그리고 원본을 보관해 링크로 남긴다.
            $data = $this->analyzer->analyze($file->getRealPath(), $mime);

            $disk = IntegratedDocument::storageDisk();
            $path = $file->store('procurement-docs', $disk);

            return response()->json([
                'success' => true,
                'data' => $data,
                'file' => [
                    'disk' => $disk,
                    'path' => $path,
                    'name' => $file->getClientOriginalName() ?: basename($path),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * 조달 항목에 연결된 근거 서류 원본 열람.
     */
    public function showFile(ProcurementItem $item)
    {
        $disk = $item->document_disk ?: 'public';
        abort_unless(filled($item->document_path) && Storage::disk($disk)->exists($item->document_path), 404);

        return Storage::disk($disk)->response(
            $item->document_path,
            $item->document_name ?: ('procurement-'.$item->id),
        );
    }
}
