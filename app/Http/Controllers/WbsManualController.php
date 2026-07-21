<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeWbsManualJob;
use App\Models\Project;
use App\Models\Site;
use App\Models\WbsManual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 공정관리 AI 매뉴얼 분석 — 파일 업로드 → 분석, 그리고 분석된 매뉴얼 리스트.
 * 선택된 AI 엔진(Claude/Gemini)이 매뉴얼(PDF/이미지) 본문을 직접 읽어 WBS 를 생성한다.
 */
class WbsManualController extends Controller
{
    /**
     * 매뉴얼 업로드 → 분석 → WBS 생성 + 이력 기록.
     */
    public function upload(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate([
            'manual' => 'required|file|mimes:pdf,png,jpg,jpeg,webp|max:32768', // 32MB
            'project_code' => 'required|string|max:80|exists:projects,project_code',
            'site_id' => 'nullable',
        ]);

        try {
            $file = $request->file('manual');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

            $projectCode = (string) $request->input('project_code');
            $project = Project::query()->where('project_code', $projectCode)->firstOrFail();
            $requestedSiteId = $this->resolveSiteId($request->input('site_id'));

            if ($requestedSiteId !== null && $project->site_id !== null && $requestedSiteId !== (int) $project->site_id) {
                return response()->json(['success' => false, 'error' => '선택한 현장과 프로젝트 현장이 일치하지 않습니다.'], 422);
            }

            $siteRowId = $requestedSiteId ?? ($project->site_id ? (int) $project->site_id : null);
            $this->authorizeSite($request, $siteRowId);
            $siteScope = $siteRowId !== null
                ? (string) (Site::query()->whereKey($siteRowId)->value('code') ?: 'ALL')
                : 'ALL';

            $path = $file->store('wbs-manuals', 'public');
            $absolutePath = Storage::disk('public')->path($path);
            $mime = $file->getClientMimeType() ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');

            $manual = WbsManual::create([
                'project_code' => $projectCode,
                'site_id' => $siteRowId,
                'original_name' => $file->getClientOriginalName() ?: basename($path),
                'disk' => 'public',
                'path' => $path,
                'mime_type' => $mime,
                'size' => (int) $file->getSize(),
                'status' => 'analyzing',
                'analyzed_by_id' => optional($request->user())->id,
            ]);

            // CPM 전량 추출은 수십 초~수 분 걸려 동기 처리 시 게이트웨이 504 가 난다.
            // 응답을 먼저 보내고(202) 분석은 그 뒤에 같은 프로세스에서 진행 — 프론트가 상태를 폴링한다.
            AnalyzeWbsManualJob::dispatch($manual->id, $projectCode, $siteScope)->afterResponse();

            return response()->json([
                'success' => true,
                'status' => 'analyzing',
                'manual' => $this->present($manual->fresh()),
            ], 202);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * 분석된 매뉴얼 리스트 (프로젝트별, 최신순).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $query = WbsManual::query()->with('analyzedBy')->latest();
        $this->scopeManualQuery($request, $query);

        if (filled($request->query('project'))) {
            $query->where('project_code', (string) $request->query('project'));
        }

        $manuals = $query->limit(50)->get()->map(fn (WbsManual $m) => $this->present($m))->all();

        return response()->json(['success' => true, 'manuals' => $manuals]);
    }

    /**
     * 저장된 매뉴얼 원본 파일 열람.
     */
    public function show(Request $request, WbsManual $manual)
    {
        $this->authorizeManage($request);
        $this->authorizeSite($request, $manual->site_id ? (int) $manual->site_id : null);
        abort_unless(Storage::disk($manual->disk ?: 'public')->exists($manual->path), 404);

        return Storage::disk($manual->disk ?: 'public')->response(
            $manual->path,
            $manual->original_name,
            ['Content-Type' => $manual->mime_type ?: 'application/octet-stream']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WbsManual $m): array
    {
        return [
            'id' => $m->id,
            'project_code' => $m->project_code,
            'original_name' => $m->original_name,
            'mime_type' => $m->mime_type,
            'size' => $m->size,
            'engine' => $m->engine,
            'status' => $m->status,
            'stages' => $m->stages,
            'tasks' => $m->tasks,
            'subtasks' => $m->subtasks,
            'error' => $m->error,
            'analyzed_by' => optional($m->analyzedBy)->name,
            'analyzed_at' => optional($m->analyzed_at)?->toDateTimeString(),
            'created_at' => optional($m->created_at)?->toDateTimeString(),
            'url' => route('wbs-manual.show', ['manual' => $m->id]),
        ];
    }

    private function resolveSiteId(mixed $site): ?int
    {
        $site = is_string($site) ? trim($site) : $site;
        if ($site === null || $site === '' || $site === 'ALL') {
            return null;
        }

        if (is_numeric($site)) {
            $resolved = Site::query()->whereKey((int) $site)->value('id');
        } else {
            $resolved = Site::query()->where('code', (string) $site)->value('id');
        }

        if (! $resolved) {
            throw new RuntimeException('선택한 현장을 찾을 수 없습니다.');
        }

        return (int) $resolved;
    }

    private function authorizeManage(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user
            && $user->account_status === 'active'
            && in_array($user->access_role, ['super_admin', 'admin', 'site_manager', 'safety_manager'], true),
            403,
        );
    }

    private function authorizeSite(Request $request, ?int $siteId): void
    {
        $user = $request->user();
        $hasGlobalAccess = $user
            && (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites');

        if ($hasGlobalAccess) {
            return;
        }

        abort_unless(
            $user
            && $user->access_scope === 'site'
            && $siteId !== null
            && (int) $user->allowed_site_id === $siteId,
            403,
        );
    }

    private function scopeManualQuery(Request $request, Builder $query): void
    {
        $user = $request->user();
        if ($user && (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites')) {
            return;
        }

        if ($user && $user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }

        $query->whereRaw('1 = 0');
    }
}
