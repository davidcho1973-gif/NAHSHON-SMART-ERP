<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeIntegratedDocumentJob;
use App\Models\IntegratedDocument;
use App\Services\IntegratedDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 문서통합관리 — 파일 업로드(멀티파트) → AI 자동분석(백그라운드), 상태 폴링, 원본 열람.
 * (조회/확정 등 JSON API 는 SmartCompanyData 의 api_doc* 로 처리한다.)
 */
class IntegratedDocumentController extends Controller
{
    public function __construct(private readonly IntegratedDocumentService $service)
    {
    }

    /**
     * 문서 업로드 → analyzing 상태로 생성 → 응답 후 AI 분석 잡 실행 → 202.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:32768', // 32MB
            'site_id' => 'nullable',
            'project_code' => 'nullable|string|max:80',
            'title' => 'nullable|string|max:255',
        ]);

        try {
            $file = $request->file('file');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

            // 형식 검증은 확장자 화이트리스트로(마임 추정이 docx=zip 로 어긋나는 문제 회피).
            $ext = strtolower($file->getClientOriginalExtension());
            $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'];
            if (! in_array($ext, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'error' => sprintf('지원하지 않는 형식입니다(.%s). PDF·이미지·Word(.docx)·Excel(.xlsx)만 업로드하세요. 한글(HWP)·구형 .doc/.xls·도면은 PDF로 변환해 올려주세요.', $ext ?: '?'),
                ], 422);
            }

            $path = $file->store('integrated-documents', 'public');
            $absolute = Storage::disk('public')->path($path);
            $mime = $file->getClientMimeType() ?: (mime_content_type($absolute) ?: 'application/octet-stream');
            $user = $request->user();

            $doc = IntegratedDocument::create([
                'site_id' => $this->service->resolveSiteId($request->input('site_id')),
                'project_code' => $request->input('project_code'),
                'title' => (string) ($request->input('title') ?: ($file->getClientOriginalName() ?: basename($path))),
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName() ?: basename($path),
                'mime_type' => $mime,
                'size' => (int) $file->getSize(),
                'status' => 'analyzing',
                'uploaded_by_id' => optional($user)->id,
                'uploaded_by_label' => optional($user)->name,
            ]);

            AnalyzeIntegratedDocumentJob::dispatch($doc->id)->afterResponse();

            return response()->json(['success' => true, 'status' => 'analyzing', 'document' => $this->present($doc)], 202);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * 상태 폴링용 — 최근 업로드(또는 특정 id 목록)의 분석 진행 상태.
     */
    public function status(Request $request): JsonResponse
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        $query = IntegratedDocument::query()->latest();
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->limit(20);
        }

        $docs = $query->get()->map(fn (IntegratedDocument $d) => $this->present($d))->all();

        return response()->json(['success' => true, 'documents' => $docs]);
    }

    /**
     * 저장된 원본 파일 열람.
     */
    public function show(IntegratedDocument $document)
    {
        $disk = $document->disk ?: 'public';
        abort_unless(filled($document->path) && Storage::disk($disk)->exists($document->path), 404);

        return Storage::disk($disk)->response(
            $document->path,
            $document->original_name ?: ('document-' . $document->id),
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IntegratedDocument $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'status' => $d->status,
            'folder_code' => $d->folder_code,
            'folder_name' => $d->folderName(),
            'document_type' => $d->document_type,
            'type_label' => IntegratedDocument::typeLabel($d->document_type),
            'type_confidence' => $d->type_confidence,
            'folder_confidence' => $d->folder_confidence,
            'duplicate' => (bool) $d->duplicate_of_id,
            'duplicate_note' => $d->duplicate_note,
            'summary' => (array) $d->summary,
            'error' => $d->error,
            'created_at' => optional($d->created_at)?->toDateTimeString(),
        ];
    }
}
