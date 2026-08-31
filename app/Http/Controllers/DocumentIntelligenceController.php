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
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentSiteAssigner;
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

        // 문은 하나다 — 주소로 직접 오면(알림 링크·즐겨찾기) ERP 본체 안의 문서함으로
        // 보낸다. 예전에는 독립 화면이 따로 떠서 왼쪽 메뉴가 두 벌이 됐다. embed=1
        // (ERP 안 iframe)일 때만 이 페이지를 그대로 그린다. 문서함을 볼 수 있는
        // 역할은 전부 ERP 가 홈이므로 돌려보내서 잃는 사람이 없다.
        if (! $request->boolean('embed')) {
            $params = array_filter([
                'view' => 'document-hub',
                'document' => $request->query('document'),
                'site_id' => $request->query('site_id'),
            ]);

            return redirect('/?'.http_build_query($params));
        }

        // 드롭존 기본 소속 — ERP 상단에서 고른 현장(site_id 파라미터)이 최우선이고,
        // 없으면 소속 규칙(현장 사람은 자기 현장, 수퍼관리자·고위관리자·회계는 Global).
        $defaultSiteId = $request->integer('site_id') ?: \App\Support\DefaultScope::siteId($request->user());
        $defaultCompanyId = $defaultSiteId
            ? (int) Site::query()->whereKey($defaultSiteId)->value('company_id')
            : \App\Support\DefaultScope::companyId($request->user());
        $defaultProjectId = \App\Support\DefaultScope::projectId($request->user(), $defaultSiteId);

        return view('document-intelligence.index', [
            'companies' => $this->companyOptions($request->user()),
            'sites' => $this->siteOptions($request->user()),
            'projects' => $this->projectOptions($request->user()),
            'canManage' => $this->canManage($request->user()),
            'maxUploadMb' => round((int) config('document-intelligence.max_upload_kb', 51200) / 1024),
            'defaultCompanyId' => $defaultCompanyId,
            'defaultSiteId' => $defaultSiteId,
            'defaultProjectId' => $defaultProjectId,
        ]);
    }

    public function documents(Request $request): JsonResponse
    {
        $this->authorizeView($request->user());

        // 화면이 고른 현장. ERP 안에 얹혀 있는 화면이라 상단 전환기의 현장을 그대로
        // 따라야 한다 — 예전에는 이 값을 아예 받지 않아, 애리조나를 띄워 놓고도 조지아
        // 문서가 목록과 통계에 그대로 떴다.
        // 'none' 은 현장이 비어 있는 문서만 — 일괄 정리 화면이 쓰는 값이다.
        $rawSite = trim((string) $request->query('site_id', ''));
        $unassignedOnly = $rawSite === 'none';
        $siteFilter = $unassignedOnly ? null : ($request->integer('site_id') ?: null);
        $onSite = fn (Builder $b) => $b
            ->when($unassignedOnly, fn (Builder $q) => $q->whereNull('site_id'))
            ->when($siteFilter, fn (Builder $q) => $q->where('site_id', $siteFilter));

        $query = IntelligentDocument::query()
            ->visibleTo($request->user())
            ->with(['company:id,name,code', 'site:id,name,code', 'project:id,name,project_code'])
            ->withCount([
                'actionItems as open_actions_count' => fn (Builder $actionQuery) => $actionQuery->whereIn('status', ['open', 'in_progress']),
            ])
            ->search($request->string('q')->toString())
            ->tap($onSite)
            ->when($request->filled('category'), fn (Builder $builder) => $builder->where('category', $request->string('category')))
            ->when($request->filled('project_id'), fn (Builder $builder) => $builder->where('project_id', $request->integer('project_id')))
            ->when($request->filled('ai_status'), fn (Builder $builder) => $builder->where('ai_status', $request->string('ai_status')))
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $documents = $query->paginate(min(100, max(10, $request->integer('per_page', 30))));
        // 위쪽 숫자 카드도 같은 스코프를 본다 — 목록은 걸러졌는데 합계만 전체면
        // "80건인데 3건만 보인다" 가 되어 사람이 화면을 믿지 않게 된다.
        $scope = IntelligentDocument::query()->visibleTo($request->user())->tap($onSite);

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
                // 현장이 비어 있는 문서는 어느 현장 화면에도 뜨지 않는다. 그래서 이
                // 숫자만은 고른 현장과 무관하게 전체를 센다 — 안 그러면 정리해야 할
                // 문서가 있다는 사실 자체가 화면에서 사라진다.
                'unassigned' => IntelligentDocument::query()
                    ->visibleTo($request->user())
                    ->whereNull('site_id')
                    ->count(),
            ],
        ]);
    }

    /** 현장이 비어 있는 문서 목록 + 제안 — 일괄 정리 화면이 연다. */
    public function unassigned(Request $request, DocumentSiteAssigner $assigner): JsonResponse
    {
        $this->authorizeManage($request->user());

        return response()->json([
            'success' => true,
            'sites' => collect($this->siteOptions($request->user()))
                ->map(fn (string $label, int $id): array => ['id' => $id, 'label' => $label])
                ->values()->all(),
            ...$assigner->pending($request->user(), $request->integer('limit', 200)),
        ]);
    }

    /**
     * 고른 문서에 현장을 한 번에 붙인다.
     *
     * site_id 를 주면 그 현장으로, 안 주면 문서마다의 제안대로. 이미 현장이 있는
     * 문서는 서비스가 걸러 낸다 — 일괄 작업이 멀쩡한 귀속을 덮으면 안 된다.
     */
    public function assignSite(Request $request, DocumentSiteAssigner $assigner): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $r = $assigner->assign($request->user(), $data['ids'], $data['site_id'] ?? null);

        $parts = [];
        if ($r['unmatched'] > 0) {
            $parts[] = "현장을 찾지 못한 {$r['unmatched']}건은 그대로 두었습니다";
        }
        if ($r['skipped'] > 0) {
            $parts[] = "손대지 않은 문서 {$r['skipped']}건";
        }

        return response()->json([
            'success' => true,
            'message' => $r['assigned'] === 0
                ? '현장을 붙인 문서가 없습니다.'.($parts ? ' '.implode(' · ', $parts).'.' : '')
                : "문서 {$r['assigned']}건에 현장을 붙였습니다.".($parts ? ' '.implode(' · ', $parts).'.' : ''),
        ] + $r);
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

        // 접수 규칙(형식·중복·유실 복원·저장·분석 큐)은 DocumentIntake 한 곳에 있다 —
        // 채팅방에 올린 파일도 같은 창구로 들어오기 때문이다. 여기서는 결과를
        // 화면이 이해하는 세 갈래(접수/중복/실패)로 나누기만 한다.
        $intake = app(DocumentIntake::class);
        $created = [];
        $duplicates = [];
        $failed = [];

        foreach ($request->file('files', []) as $file) {
            $originalName = $file->getClientOriginalName();
            // 한 파일이 막혀도 나머지는 올라가야 한다 — 배치 전체를 죽이지 않는다.
            $result = $intake->ingest(
                $file,
                ['company_id' => $companyId, 'site_id' => $siteId, 'project_id' => $projectId],
                ['uploaded_by' => $request->user()->id, 'source' => 'dropzone'],
            );

            match ($result['status']) {
                'created', 'restored' => $created[] = $this->documentRow($result['document']),
                'duplicate' => $duplicates[] = [
                    'file' => $originalName,
                    'documentId' => $result['document']?->id,
                    'requeued' => $result['requeued'],
                    'reason' => $result['reason'],
                ],
                default => $failed[] = ['file' => $originalName, 'reason' => $result['reason']],
            };
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

    /**
     * 도면에서 물량을 뽑아 BOQ 대장에 바로 넣는다.
     *
     * 승인 대기줄은 없다 — 확신이 서는 줄은 그냥 들어가고 애매한 줄만 표시된다.
     * 그래서 결과 문구가 "몇 줄 넣었고 그중 몇 줄을 봐야 하는지" 를 말한다.
     */
    public function takeoff(Request $request, IntelligentDocument $document): JsonResponse
    {
        $this->authorizeManage($request->user());
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();

        // 판독은 수십 초에서 몇 분이 걸린다 — 요청 안에서 붙잡고 있으면 게이트웨이가
        // 먼저 끊어 504 가 된다. 접수만 하고 번호표를 준다.
        return response()->json(\App\Services\Takeoff\AiJobQueue::push(
            'takeoff', 'document', $document->id,
            '도면 물량 뽑기 — '.($document->document_number ?: $document->title ?: $document->original_file_name),
            ['discipline' => $request->string('discipline')->toString() ?: null],
        ), 202);
    }

    /**
     * 오래 걸리는 AI 작업의 진행 상태 — 화면이 번호표로 물어본다.
     *
     * 도면 판독은 몇 분이 걸릴 수 있어 요청 안에서 기다릴 수 없다(게이트웨이가 끊어
     * 504 가 된다). 접수 때 받은 번호표로 여기에 물으면 끝났는지, 결과가 무엇인지 준다.
     */
    public function aiJob(Request $request, int $job): JsonResponse
    {
        $this->authorizeManage($request->user());

        return response()->json(\App\Services\Takeoff\AiJobQueue::status($job));
    }

    /** 시방서에서 제출물 요구를 전수로 뽑아 제출물 대장에 바로 넣는다. */
    public function extractSubmittals(Request $request, IntelligentDocument $document): JsonResponse
    {
        $this->authorizeManage($request->user());
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();

        return response()->json(\App\Services\Takeoff\AiJobQueue::push(
            'spec_submittals', 'document', $document->id,
            '시방 제출물 뽑기 — '.($document->title ?: $document->original_file_name),
        ), 202);
    }

    /**
     * 문서 정보 수정 — 사람이 AI 분류를 바로잡는 창구.
     *
     * AI 작업 번호표 리팩터링 때 실수로 지워졌던 메서드다 — 라우트는 남아 있는데
     * 메서드가 없어 수정 저장이 전부 500 으로 죽었다. 그대로 복원한다.
     */
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

    public function preview(Request $request, IntelligentDocument $document): \Symfony\Component\HttpFoundation\Response
    {
        $document = $this->scopedDocument($request->user(), $document->id)->firstOrFail();
        abort_unless(
            $this->fileStillThere((string) $document->disk, (string) $document->file_path),
            404,
            '원본 파일이 서버에 없습니다. 같은 파일을 문서함에 다시 올리면 이 문서에 복원됩니다.'
        );
        $disk = Storage::disk($document->disk);

        // 엑셀은 표로, 워드는 문서로 — 브라우저가 못 그리는 형식은 서버가 HTML 로
        // 바꿔 보여준다. 예전에는 여기서 415 로 밀어내서 화면이 추출 텍스트로 후퇴했다.
        // 여기서도 확장자 칸이 비었으면 파일 이름을 본다.
        $ext = (string) ($document->extension ?: pathinfo((string) $document->original_file_name, PATHINFO_EXTENSION));

        if (\App\Support\OfficePreview::supports($ext)) {
            $html = \App\Support\OfficePreview::html(
                (string) $disk->get($document->file_path),
                $ext,
                (string) ($document->title ?: $document->original_file_name),
            );
            if ($html !== null) {
                return response($html, 200, \App\Support\OfficePreview::safeHeaders());
            }
        }

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
        // 확장자 칸을 믿을 수 없다 — 일괄 임포트로 들어온 문서는 이 칸이 비어 있어
        // PDF 인데도 415 로 막혔다(703K 도면·시방 전부가 그랬다). 그래서 파일 이름과
        // MIME 까지 함께 본다. 셋 중 하나라도 알아보면 열어 준다.
        $extension = strtolower((string) $document->extension);
        if (! isset($previewTypes[$extension])) {
            $fromName = strtolower((string) pathinfo((string) $document->original_file_name, PATHINFO_EXTENSION));
            if (isset($previewTypes[$fromName])) {
                $extension = $fromName;
            } else {
                $byMime = array_search(
                    strtolower(trim(explode(';', (string) $document->mime_type)[0])),
                    array_map(fn (string $t): string => strtolower(trim(explode(';', $t)[0])), $previewTypes),
                    true,
                );
                if (is_string($byMime)) {
                    $extension = $byMime;
                }
            }
        }

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
        }, \Illuminate\Support\Str::slug(\App\Support\Org::name()).'-document-index-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            'extension' => strtolower((string) $document->extension),
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
}
