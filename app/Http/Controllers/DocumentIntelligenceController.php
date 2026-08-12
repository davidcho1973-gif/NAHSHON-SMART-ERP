<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\Company;
use App\Models\DocumentActionItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Documents\StuckAnalysisReaper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentIntelligenceController extends Controller
{
    private const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];

    private const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'];

    public function index(Request $request)
    {
        $this->authorizeView($request->user());

        return view('document-intelligence.index', [
            'companies' => $this->companyOptions($request->user()),
            'sites' => $this->siteOptions($request->user()),
            'projects' => $this->projectOptions($request->user()),
            'canManage' => $this->canManage($request->user()),
            'maxUploadMb' => round((int) config('document-intelligence.max_upload_kb', 51200) / 1024),
        ]);
    }

    public function documents(Request $request): JsonResponse
    {
        $this->authorizeView($request->user());

        $query = IntelligentDocument::query()
            ->visibleTo($request->user())
            ->with(['company:id,name,code', 'site:id,name,code', 'project:id,name,project_code'])
            ->withCount([
                'actionItems as open_actions_count' => fn (Builder $actionQuery) => $actionQuery->whereIn('status', ['open', 'in_progress']),
            ])
            ->search($request->string('q')->toString())
            ->when($request->filled('category'), fn (Builder $builder) => $builder->where('category', $request->string('category')))
            ->when($request->filled('project_id'), fn (Builder $builder) => $builder->where('project_id', $request->integer('project_id')))
            ->when($request->filled('ai_status'), fn (Builder $builder) => $builder->where('ai_status', $request->string('ai_status')))
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $documents = $query->paginate(min(100, max(10, $request->integer('per_page', 30))));
        $scope = IntelligentDocument::query()->visibleTo($request->user());

        return response()->json([
            'success' => true,
            'documents' => collect($documents->items())->map(fn (IntelligentDocument $document): array => $this->documentRow($document))->all(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'total' => $documents->total(),
            ],
            'stats' => [
                'total' => (clone $scope)->count(),
                'analyzing' => (clone $scope)->whereIn('ai_status', ['queued', 'analyzing'])->count(),
                'review_required' => (clone $scope)->where('ai_status', 'review_required')->count(),
                'open_actions' => DocumentActionItem::query()
                    ->whereIn('intelligent_document_id', (clone $scope)->select('id'))
                    ->whereIn('status', ['open', 'in_progress'])
                    ->count(),
                'critical_actions' => DocumentActionItem::query()
                    ->whereIn('intelligent_document_id', (clone $scope)->select('id'))
                    ->whereIn('status', ['open', 'in_progress'])
                    ->whereIn('severity', ['critical', 'high'])
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, IntelligentDocument $document): JsonResponse
    {
        $document = $this->scopedDocument($request->user(), $document->id)
            ->with(['company:id,name,code', 'site:id,name,code', 'project:id,name,project_code', 'actionItems'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'document' => [
                ...$this->documentRow($document),
                'summary' => $document->summary,
                'keyFacts' => $document->key_facts ?: [],
                'keywords' => $document->keywords ?: [],
                'tags' => $document->tags ?: [],
                'extractedText' => $document->extracted_text,
                'aiError' => $document->ai_error,
                'aiPayload' => $document->ai_payload,
                'downloadUrl' => route('document-intelligence.download', $document),
                'previewUrl' => route('document-intelligence.preview', $document),
                'actions' => $document->actionItems->map(fn (DocumentActionItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->action_type,
                    'module' => $item->related_module,
                    'severity' => $item->severity,
                    'status' => $item->status,
                    'title' => $item->title,
                    'details' => $item->details,
                    'recommendedAction' => $item->recommended_action,
                    'sourceExcerpt' => $item->source_excerpt,
                    'dueAt' => $item->due_at?->toIso8601String(),
                    'confidence' => $item->confidence,
                ])->all(),
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->authorizeManage($request->user());

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['required', 'file', 'max:'.config('document-intelligence.max_upload_kb', 51200)],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        [$companyId, $siteId, $projectId] = $this->validatedScope(
            $request->user(),
            $request->integer('company_id') ?: null,
            $request->integer('site_id') ?: null,
            $request->integer('project_id') ?: null,
        );

        // 디스크는 여기서 미리 열지 않는다 — 설정이 잘못돼 있으면 Storage::disk() 자체가
        // 예외를 던져 요청 전체가 500 으로 죽고, 화면에는 이유 없이 "업로드 실패"만 뜬다.
        // 실제 저장은 storeUpload() 안에서 예외를 잡아 파일별로 처리한다.
        $diskName = (string) config('document-intelligence.disk', 'local');
        $allowedExtensions = config('document-intelligence.allowed_extensions', []);
        $created = [];
        $duplicates = [];
        $failed = [];

        foreach ($request->file('files', []) as $file) {
            $originalName = $file->getClientOriginalName();
            $extension = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($extension, $allowedExtensions, true)) {
                // 한 파일이 형식 때문에 막혀도 나머지는 올라가야 한다 — 배치 전체를 죽이지 않는다.
                $failed[] = ['file' => $originalName, 'reason' => '지원하지 않는 파일 형식입니다.'];

                continue;
            }

            $sha256 = hash_file('sha256', $file->getRealPath());
            $duplicateQuery = IntelligentDocument::query()->where('sha256', $sha256);
            foreach (['company_id' => $companyId, 'site_id' => $siteId, 'project_id' => $projectId] as $column => $value) {
                $value ? $duplicateQuery->where($column, $value) : $duplicateQuery->whereNull($column);
            }
            $duplicate = $duplicateQuery->first();
            $safeName = $this->safeFileName($originalName);

            if ($duplicate) {
                // 원본이 유실된 레코드(배포로 로컬 디스크가 초기화된 경우)는 "중복"으로
                // 거부하면 안 된다 — 같은 파일을 다시 올릴 유일한 길을 막아 버린다.
                $lost = blank($duplicate->file_path) || ! $this->fileStillThere($duplicate->disk ?: $diskName, (string) $duplicate->file_path);

                if ($lost) {
                    $uuid = $duplicate->uuid ?: (string) Str::uuid();
                    [$path, $usedDisk, $error] = $this->storeUpload($file, $uuid, $safeName, $diskName);

                    if ($path === null) {
                        // 저장 실패를 "중복"으로 뭉뚱그리면 사용자는 원인을 영영 알 수 없다.
                        $failed[] = ['file' => $originalName, 'reason' => $error ?: '파일 저장에 실패했습니다.'];

                        continue;
                    }

                    $duplicate->update([
                        'disk' => $usedDisk,
                        'file_path' => $path,
                        'stored_file_name' => $safeName,
                        'ai_status' => 'queued',
                        'ai_error' => null,
                    ]);
                    AnalyzeIntelligentDocumentJob::dispatch($duplicate->id)->afterResponse();
                    $created[] = $this->documentRow($duplicate->fresh());

                    continue;
                }

                // 원본은 멀쩡한 진짜 중복. 다만 예전 분석이 실패로 남아 있으면 이 기회에
                // 다시 태운다 — 사용자가 같은 파일을 또 올리는 이유는 대개 그것이다.
                $requeued = false;
                if (in_array((string) $duplicate->ai_status, ['failed', 'queued'], true)) {
                    $duplicate->update(['ai_status' => 'queued', 'ai_error' => null]);
                    AnalyzeIntelligentDocumentJob::dispatch($duplicate->id)->afterResponse();
                    $requeued = true;
                }

                $duplicates[] = [
                    'file' => $originalName,
                    'documentId' => $duplicate->id,
                    'requeued' => $requeued,
                    'reason' => $requeued
                        ? '이미 등록된 문서입니다 — 분석을 다시 시작했습니다.'
                        : '이미 등록된 문서입니다.',
                ];

                continue;
            }

            $uuid = (string) Str::uuid();
            [$path, $usedDisk, $error] = $this->storeUpload($file, $uuid, $safeName, $diskName);
            if ($path === null) {
                $failed[] = ['file' => $originalName, 'reason' => $error ?: '파일 저장에 실패했습니다.'];

                continue;
            }

            $document = IntelligentDocument::query()->create([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'project_id' => $projectId,
                'uploaded_by' => $request->user()->id,
                'source' => 'dropzone',
                'disk' => $usedDisk,
                'file_path' => $path,
                'original_file_name' => $originalName,
                'stored_file_name' => $safeName,
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'extension' => $extension,
                'file_size' => $file->getSize() ?: 0,
                'sha256' => $sha256,
                'title' => pathinfo($originalName, PATHINFO_FILENAME),
                'received_at' => now(),
                'ai_status' => 'queued',
            ]);

            AnalyzeIntelligentDocumentJob::dispatch($document->id)->afterResponse();
            $created[] = $this->documentRow($document);
        }

        return response()->json([
            'success' => true,
            'message' => count($created).'개 문서를 접수했고 AI 분석을 시작했습니다.',
            'documents' => $created,
            'duplicates' => $duplicates,
            'failed' => $failed,
        ], 202);
    }

    /** 디스크를 열 수 없으면 "파일 없음"으로 본다 — 판정 하나 때문에 업로드가 죽으면 안 된다. */
    private function fileStillThere(string $diskName, string $path): bool
    {
        try {
            return $path !== '' && Storage::disk($diskName)->exists($path);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * 업로드 원본을 저장한다. 설정 디스크가 실패하면 local 로 내려 받아 둔다.
     *
     * s3 디스크는 config 에서 'throw' => false 라 쓰기가 실패해도 예외 없이 false 만
     * 돌려준다. 그대로 두면 "저장 실패"가 화면에서는 "중복 N개 제외"처럼 보여
     * 사용자는 왜 안 올라가는지 영영 알 수 없다. 그래서 (1) 이유를 남기고
     * (2) 최소한 로컬에라도 받아 분석은 진행되게 한다.
     *
     * @return array{0: ?string, 1: string, 2: ?string} [path, disk, error]
     */
    private function storeUpload(mixed $file, string $uuid, string $safeName, string $diskName): array
    {
        $dir = "document-intelligence/inbox/{$uuid}";

        try {
            $path = Storage::disk($diskName)->putFileAs($dir, $file, $safeName);
            if ($path) {
                return [$path, $diskName, null];
            }
            $reason = "저장소('{$diskName}')가 파일을 받지 못했습니다.";
        } catch (\Throwable $e) {
            report($e);
            $reason = "저장소('{$diskName}') 오류: ".$e->getMessage();
        }

        Log::warning('문서 업로드 저장 실패 — 로컬로 대체합니다: '.$reason);

        if ($diskName !== 'local') {
            try {
                $path = Storage::disk('local')->putFileAs($dir, $file, $safeName);
                if ($path) {
                    // 올라가긴 했지만 저장소 설정이 잘못돼 있다 — 다음 배포에 사라질 수 있다.
                    return [$path, 'local', null];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [null, $diskName, $reason];
    }

    /**
     * "AI 분석 중"에 갇힌 문서를 한 번에 되살린다.
     *
     * 스케줄러가 10분마다 같은 일을 하지만, 스케줄러가 꺼진 환경도 있고 사용자는
     * 지금 당장 풀고 싶다. 규칙은 한 곳(StuckAnalysisReaper)에 두고 둘이 공유한다.
     */
    public function reanalyzeStuck(Request $request, StuckAnalysisReaper $reaper): JsonResponse
    {
        $this->authorizeManage($request->user());

        $r = $reaper->reap((int) $request->integer('minutes', 10));

        return response()->json([
            'success' => true,
            'message' => $r['total'] === 0
                ? '멈춘 문서가 없습니다.'
                : "멈춘 문서 {$r['total']}건 — 재시도 {$r['requeued']}건, 실패 표시 {$r['failed']}건.",
        ] + $r);
    }

    public function reanalyze(Request $request, IntelligentDocument $document): JsonResponse
    {
        $this->authorizeManage($request->user());
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();
        $document->update(['ai_status' => 'queued', 'ai_error' => null]);
        AnalyzeIntelligentDocumentJob::dispatch($document->id)->afterResponse();

        return response()->json(['success' => true, 'message' => 'AI 재분석을 시작했습니다.'], 202);
    }

    public function review(Request $request, IntelligentDocument $document): JsonResponse
    {
        $this->authorizeManage($request->user());
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(IntelligentDocument::CATEGORY_OPTIONS))],
            'document_type' => ['required', 'string', 'in:'.implode(',', array_keys(IntelligentDocument::TYPE_OPTIONS))],
            'discipline' => ['nullable', 'string', 'max:80'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'revision' => ['nullable', 'string', 'max:40'],
            'document_date' => ['nullable', 'date'],
            'response_due_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
        ]);

        $document->update([
            ...$data,
            'ai_status' => 'ready',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true, 'document' => $this->documentRow($document->fresh())]);
    }

    /**
     * 문서 삭제 — 레코드와 원본 파일을 함께 지운다.
     *
     * 잘못 올린 문서, 중복 스캔, 폐기된 개정본은 목록에 남아 있으면 그 자체가 오정보다.
     * 원본 파일을 남기면 저장소만 먹으므로 같이 지운다 — 다만 파일 삭제가 실패해도
     * (이미 없거나 저장소 오류) 레코드 삭제는 진행한다. 화면에서 지웠는데 그대로
     * 남아 있는 것이 더 나쁘다.
     */
    public function destroy(Request $request, IntelligentDocument $document): JsonResponse
    {
        $this->authorizeManage($request->user());
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();

        $name = $document->original_file_name;

        if (filled($document->file_path)) {
            try {
                Storage::disk($document->disk)->delete($document->file_path);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // 이 문서에서 나온 후속조치·알림도 함께 정리한다 — 원본이 없어진 조치는
        // 처리할 방법이 없는데 목록에만 남아 "미처리"로 계속 센다.
        UnifiedAlert::query()
            ->where('source_type', IntelligentDocument::class)
            ->where('source_id', (string) $document->id)
            ->delete();
        $actionIds = DocumentActionItem::query()
            ->where('intelligent_document_id', $document->id)
            ->pluck('id');
        if ($actionIds->isNotEmpty()) {
            UnifiedAlert::query()
                ->where('source_type', DocumentActionItem::class)
                ->whereIn('source_id', $actionIds->map(fn ($id) => (string) $id))
                ->delete();
            DocumentActionItem::query()->whereIn('id', $actionIds)->delete();
        }

        $document->delete();

        return response()->json(['success' => true, 'message' => "'{$name}' 을(를) 삭제했습니다."]);
    }

    public function updateAction(Request $request, DocumentActionItem $action): JsonResponse
    {
        $this->authorizeManage($request->user());
        $document = $this->scopedDocument($request->user(), $action->intelligent_document_id)->firstOrFail();
        abort_unless($document->id === $action->intelligent_document_id, 403);

        $status = $request->validate(['status' => ['required', 'in:open,in_progress,completed,ignored']])['status'];
        $action->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
        UnifiedAlert::query()
            ->where('source_type', DocumentActionItem::class)
            ->where('source_id', (string) $action->id)
            ->update([
                'status' => match ($status) {
                    'completed' => 'completed',
                    'ignored' => 'ignored',
                    'in_progress' => 'in_progress',
                    default => 'unresolved',
                },
                'resolved_at' => in_array($status, ['completed', 'ignored'], true) ? now() : null,
            ]);

        return response()->json(['success' => true]);
    }

    public function download(Request $request, IntelligentDocument $document): StreamedResponse
    {
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();
        abort_unless(
            $this->fileStillThere((string) $document->disk, (string) $document->file_path),
            404,
            '원본 파일이 서버에 없습니다. 서버 배포로 저장소가 초기화된 문서입니다 — 같은 파일을 문서함에 다시 올리면 이 문서에 복원됩니다.'
        );
        $disk = Storage::disk($document->disk);

        return $disk->download($document->file_path, $document->original_file_name, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, IntelligentDocument $document): StreamedResponse
    {
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();
        abort_unless(
            $this->fileStillThere((string) $document->disk, (string) $document->file_path),
            404,
            '원본 파일이 서버에 없습니다. 같은 파일을 문서함에 다시 올리면 이 문서에 복원됩니다.'
        );
        $disk = Storage::disk($document->disk);

        $previewTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'txt' => 'text/plain; charset=UTF-8',
            'csv' => 'text/csv; charset=UTF-8',
        ];
        $extension = strtolower((string) $document->extension);
        abort_unless(isset($previewTypes[$extension]), 415, '브라우저 미리보기를 지원하지 않는 형식입니다. 다운로드해서 확인해 주세요.');

        return $disk->response($document->file_path, $document->original_file_name, [
            'Content-Type' => $previewTypes[$extension],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function exportIndex(Request $request): StreamedResponse
    {
        $this->authorizeView($request->user());
        $documents = IntelligentDocument::query()
            ->visibleTo($request->user())
            ->with(['company', 'site', 'project'])
            ->orderBy('virtual_path')
            ->orderByDesc('document_date')
            ->get();

        return response()->streamDownload(function () use ($documents): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', '회사', '현장', 'PROJECT', '가상 폴더', '분류', '문서유형', '문서번호', 'Revision', '문서일', '회신기한', '만료일', '제목', '파일명', 'AI 상태', '키워드', '요약']);
            foreach ($documents as $document) {
                fputcsv($stream, [
                    $document->uuid,
                    $document->company?->name,
                    $document->site?->code,
                    $document->project?->project_code,
                    $document->virtual_path,
                    $document->category,
                    $document->document_type,
                    $document->document_number,
                    $document->revision,
                    $document->document_date?->toDateString(),
                    $document->response_due_on?->toDateString(),
                    $document->expires_on?->toDateString(),
                    $document->displayTitle(),
                    $document->original_file_name,
                    $document->ai_status,
                    implode(', ', $document->keywords ?: []),
                    $document->summary,
                ]);
            }
            fclose($stream);
        }, 'NAHSHON-Document-Index-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function scopedDocument(User $user, int $id): Builder
    {
        $this->authorizeView($user);

        return IntelligentDocument::query()->visibleTo($user)->whereKey($id);
    }

    /** @return array{0: int|null, 1: int|null, 2: int|null} */
    private function validatedScope(User $user, ?int $companyId, ?int $siteId, ?int $projectId): array
    {
        $project = $projectId ? Project::query()->findOrFail($projectId) : null;
        $site = $project?->site ?: ($siteId ? Site::query()->findOrFail($siteId) : null);

        if ($project && $site && $project->site_id !== $site->id) {
            throw ValidationException::withMessages(['project_id' => 'PROJECT와 현장이 일치하지 않습니다.']);
        }
        if ($site && $companyId && $site->company_id !== $companyId) {
            throw ValidationException::withMessages(['company_id' => '회사와 현장이 일치하지 않습니다.']);
        }

        $siteId = $project?->site_id ?: $site?->id;
        $companyId = $project?->company_id ?: $site?->company_id ?: $companyId;

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return [$companyId, $siteId, $projectId];
        }

        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            $site = Site::query()->findOrFail($user->allowed_site_id);
            if ($projectId && ! Project::query()->whereKey($projectId)->where('site_id', $site->id)->exists()) {
                throw ValidationException::withMessages(['project_id' => '허용된 현장의 PROJECT만 선택할 수 있습니다.']);
            }

            return [$site->company_id, $site->id, $projectId];
        }

        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            if ($siteId && ! Site::query()->whereKey($siteId)->where('company_id', $user->allowed_company_id)->exists()) {
                throw ValidationException::withMessages(['site_id' => '허용된 회사의 현장만 선택할 수 있습니다.']);
            }
            if ($projectId && ! Project::query()->whereKey($projectId)->where('company_id', $user->allowed_company_id)->exists()) {
                throw ValidationException::withMessages(['project_id' => '허용된 회사의 PROJECT만 선택할 수 있습니다.']);
            }

            return [$user->allowed_company_id, $siteId, $projectId];
        }

        throw ValidationException::withMessages(['files' => '문서를 업로드할 수 있는 회사·현장 범위가 없습니다.']);
    }

    /** @return array<int, string> */
    private function companyOptions(User $user): array
    {
        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return Company::query()->orderBy('name')->pluck('name', 'id')->all();
        }

        $companyId = $user->access_scope === 'company'
            ? $user->allowed_company_id
            : Site::query()->whereKey($user->allowed_site_id)->value('company_id');

        return $companyId ? Company::query()->whereKey($companyId)->pluck('name', 'id')->all() : [];
    }

    /** @return array<int, string> */
    private function siteOptions(User $user): array
    {
        $query = Site::query()->orderBy('code');
        if (! in_array($user->access_role, ['super_admin', 'admin'], true) && $user->access_scope !== 'all_sites') {
            match ($user->access_scope) {
                'company' => $query->where('company_id', $user->allowed_company_id),
                'site' => $query->whereKey($user->allowed_site_id),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query->get()->mapWithKeys(fn (Site $site): array => [$site->id => $site->code.' - '.$site->name])->all();
    }

    /** @return array<int, string> */
    private function projectOptions(User $user): array
    {
        $query = Project::query()->orderBy('project_code');
        if (! in_array($user->access_role, ['super_admin', 'admin'], true) && $user->access_scope !== 'all_sites') {
            match ($user->access_scope) {
                'company' => $query->where('company_id', $user->allowed_company_id),
                'site' => $query->where('site_id', $user->allowed_site_id),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query->get()->mapWithKeys(fn (Project $project): array => [$project->id => $project->project_code.' - '.$project->name])->all();
    }

    private function authorizeView(?User $user): void
    {
        abort_unless($user && $user->account_status === 'active' && in_array($user->access_role, self::VIEW_ROLES, true), 403);
    }

    private function authorizeManage(?User $user): void
    {
        $this->authorizeView($user);
        abort_unless($this->canManage($user), 403);
    }

    private function canManage(?User $user): bool
    {
        return $user && in_array($user->access_role, self::MANAGE_ROLES, true);
    }

    /** @return array<string, mixed> */
    private function documentRow(IntelligentDocument $document): array
    {
        return [
            'id' => $document->id,
            'uuid' => $document->uuid,
            'title' => $document->displayTitle(),
            'fileName' => $document->original_file_name,
            'mimeType' => $document->mime_type,
            'fileSize' => $document->file_size,
            // 원본이 실제로 남아 있는지 — 배포로 로컬 디스크가 초기화되면 레코드만 남고
            // 파일은 사라진다. 그 상태를 화면에서 알 수 없으면 사용자는 다운로드를
            // 눌러 보고 나서야 404 를 만난다.
            'fileMissing' => ! $this->fileStillThere((string) $document->disk, (string) $document->file_path),
            'category' => $document->category,
            'categoryLabel' => IntelligentDocument::CATEGORY_OPTIONS[$document->category] ?? $document->category,
            'documentType' => $document->document_type,
            'documentTypeLabel' => IntelligentDocument::TYPE_OPTIONS[$document->document_type] ?? $document->document_type,
            'discipline' => $document->discipline,
            'documentNumber' => $document->document_number,
            'revision' => $document->revision,
            'direction' => $document->direction,
            'virtualPath' => $document->virtual_path,
            'company' => $document->company?->name,
            'site' => $document->site?->code,
            'project' => $document->project?->project_code,
            'projectName' => $document->project?->name,
            'documentDate' => $document->document_date?->toDateString(),
            'responseDueOn' => $document->response_due_on?->toDateString(),
            'expiresOn' => $document->expires_on?->toDateString(),
            'aiStatus' => $document->ai_status,
            'aiConfidence' => $document->ai_confidence,
            'openActions' => (int) ($document->open_actions_count ?? $document->actionItems()->whereIn('status', ['open', 'in_progress'])->count()),
            'receivedAt' => $document->received_at?->toIso8601String(),
        ];
    }

    private function safeFileName(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'document';

        return Str::limit($base, 120, '').($extension ? '.'.$extension : '');
    }
}
