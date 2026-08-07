<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeWbsManualJob;
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
 *
 * 권한: 매뉴얼은 원청 도면·시방·계약 조건이 담긴 내부 문서다. 작업자나 열람 전용(원청) 계정이
 * 남의 현장 매뉴얼을 열어보면 안 되므로, 세 엔드포인트 모두 역할과 현장 범위를 서버에서 막는다.
 * 프론트에서 버튼을 숨기는 것은 권한 경계가 아니다.
 */
class WbsManualController extends Controller
{
    /** 매뉴얼을 올리고 목록을 볼 수 있는 역할. */
    private const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager', 'safety_manager'];

    /** 현장 제한 없이 전체를 다루는 역할. */
    private const GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * 매뉴얼 업로드 → 분석 → WBS 생성 + 이력 기록.
     */
    public function upload(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate([
            'manual' => 'required|file|mimes:pdf,png,jpg,jpeg,webp|max:32768', // 32MB
            'project_code' => 'required|string|max:80',
            'site_id' => 'nullable',
        ]);

        $projectCode = (string) $request->input('project_code');
        $siteRowId = $this->resolveSiteId($request->input('site_id'));
        $siteScope = $this->resolveSiteScope($request->input('site_id'));

        // 현장 담당자가 남의 현장(또는 전체)으로 올리는 것을 막는다.
        // try 바깥에 둬야 403 이 아래 catch 에서 400 으로 뭉개지지 않는다.
        $this->authorizeSite($request, $siteRowId);

        try {
            $file = $request->file('manual');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

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
        // 아이디만 바꿔 남의 현장 매뉴얼을 내려받는 경로(IDOR)를 막는다.
        $this->authorizeSite($request, $manual->site_id);

        abort_unless(Storage::disk($manual->disk ?: 'public')->exists($manual->path), 404);

        return Storage::disk($manual->disk ?: 'public')->response(
            $manual->path,
            $manual->original_name,
            ['Content-Type' => $manual->mime_type ?: 'application/octet-stream']
        );
    }

    /**
     * 매뉴얼을 다룰 수 있는 역할인가. 작업자·열람 전용(원청)·비활성 계정은 여기서 끊긴다.
     */
    private function authorizeManage(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user
            && $user->account_status === 'active'
            && in_array($user->access_role, self::MANAGE_ROLES, true),
            403,
        );
    }

    /**
     * 이 계정이 해당 현장을 다룰 수 있는가.
     *
     * 전체 권한이 아니면 자기 현장 하나만 허용한다. $siteId 가 null(= 전체) 이면 현장 담당자는
     * 소유를 증명할 수 없으므로 거부한다.
     */
    private function authorizeSite(Request $request, ?int $siteId): void
    {
        $user = $request->user();

        if ($user && (in_array($user->access_role, self::GLOBAL_ROLES, true) || $user->access_scope === 'all_sites')) {
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

    /**
     * 목록을 계정의 현장 범위로 좁힌다. 범위를 판단할 수 없으면 아무것도 보여주지 않는다
     * (기본값이 "전부 보임" 이면 역할이 하나 늘 때마다 조용히 새는 구멍이 생긴다).
     *
     * @param  Builder<WbsManual>  $query
     */
    private function scopeManualQuery(Request $request, Builder $query): void
    {
        $user = $request->user();

        if ($user && (in_array($user->access_role, self::GLOBAL_ROLES, true) || $user->access_scope === 'all_sites')) {
            return;
        }

        if ($user && $user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }

        $query->whereRaw('1 = 0');
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
            return (int) $site;
        }

        return Site::query()->where('code', (string) $site)->value('id');
    }

    private function resolveSiteScope(mixed $site): string
    {
        $site = is_string($site) ? trim($site) : $site;
        if ($site === null || $site === '' || $site === 'ALL') {
            return 'ALL';
        }

        if (is_numeric($site)) {
            return (string) (Site::query()->whereKey((int) $site)->value('code') ?: 'ALL');
        }

        return (string) $site;
    }
}
