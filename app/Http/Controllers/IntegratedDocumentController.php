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
        $maxKb = (int) config('filesystems.documents_max_kb', 262144); // 기본 256MB
        $request->validate([
            'file' => 'required|file|max:' . $maxKb,
            'site_id' => 'nullable',
            'project_code' => 'nullable|string|max:80',
            'title' => 'nullable|string|max:255',
            'folder_code' => 'nullable|string|max:8',
        ], [
            'file.max' => sprintf('파일이 너무 큽니다. 최대 %dMB 까지 업로드할 수 있습니다.', intdiv($maxKb, 1024)),
        ]);

        try {
            $file = $request->file('file');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

            // 형식 검증은 확장자 화이트리스트로(마임 추정이 docx=zip 로 어긋나는 문제 회피).
            $ext = strtolower($file->getClientOriginalExtension());
            // AI 가 본문을 읽어 자동분석하는 형식.
            $analyzable = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx', 'pptx'];
            // 본문 추출이 안 되는 형식 — 분석 없이 "보관 등록"(파일 저장 + 폴더 수동 확인).
            $storageOnly = ['dwg', 'dxf', 'ppt', 'doc', 'xls', 'hwp'];
            $allowed = array_merge($analyzable, $storageOnly);
            if (! in_array($ext, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'error' => sprintf('지원하지 않는 형식입니다(.%s). PDF·이미지·Office(docx/xlsx/pptx)·CAD(dwg/dxf)만 업로드하세요.', $ext ?: '?'),
                ], 422);
            }

            // 영구 보관 디스크에 저장(오브젝트 스토리지 설정 시 배포에도 유실되지 않음).
            $disk = IntegratedDocument::storageDisk();
            $path = $file->store('integrated-documents', $disk);
            // 마임은 업로드 시점(로컬 임시파일)에서 판별 — 원격 디스크에서도 안전.
            $mime = $file->getClientMimeType() ?: ($file->getMimeType() ?: 'application/octet-stream');
            $user = $request->user();

            // 업로드할 때 폴더를 직접 고른 경우 — AI 자동분류 대신 이 폴더로 고정한다.
            $chosenFolder = (string) $request->input('folder_code', '');
            $chosenFolder = isset(IntegratedDocument::folderMap()[$chosenFolder]) ? $chosenFolder : null;

            $doc = IntegratedDocument::create([
                // 폴더를 안 골랐으면 folder_code 를 넘기지 않는다(컬럼 기본값 '03' 유지 → AI 가 이후 분류).
                ...($chosenFolder !== null ? ['folder_code' => $chosenFolder, 'folder_locked' => true] : []),
                'site_id' => $this->service->resolveSiteId($request->input('site_id')),
                'project_code' => $request->input('project_code'),
                'title' => (string) ($request->input('title') ?: ($file->getClientOriginalName() ?: basename($path))),
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName() ?: basename($path),
                'mime_type' => $mime,
                'size' => (int) $file->getSize(),
                'status' => 'analyzing',
                'uploaded_by_id' => optional($user)->id,
                'uploaded_by_label' => optional($user)->name,
            ]);

            // 대용량은 AI 분석을 건너뛰고 보관만 한다 — 메모리 폭주/무한 '분석중' 방지.
            $analyzeMaxBytes = ((int) config('filesystems.documents_analyze_max_kb', 51200)) * 1024;
            $tooBigToAnalyze = (int) $file->getSize() > $analyzeMaxBytes;

            if (in_array($ext, $analyzable, true) && ! $tooBigToAnalyze) {
                AnalyzeIntegratedDocumentJob::dispatch($doc->id)->afterResponse();

                return response()->json(['success' => true, 'status' => 'analyzing', 'document' => $this->present($doc)], 202);
            }

            // 보관 등록 형식(CAD 도면·구형 오피스) 또는 대용량 파일 — AI 없이 즉시 폴더 배정.
            $doc = $this->service->registerWithoutAi($doc, $ext);

            return response()->json(['success' => true, 'status' => 'needs_review', 'document' => $this->present($doc)], 201);
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

        // 엑셀·워드·파워포인트는 브라우저가 못 그리므로, 내려받기를 명시하지 않는 한
        // 서버가 HTML 로 바꿔 보여준다. 문서함(document-hub) 미리보기와 같은 규칙이다.
        $extension = strtolower(pathinfo((string) ($document->original_name ?: $document->path), PATHINFO_EXTENSION));
        if (! request()->boolean('download') && \App\Support\OfficePreview::supports($extension)) {
            $html = \App\Support\OfficePreview::html(
                (string) Storage::disk($disk)->get($document->path),
                $extension,
                (string) ($document->title ?: $document->original_name),
            );
            if ($html !== null) {
                return response($html, 200, \App\Support\OfficePreview::safeHeaders());
            }
        }

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
