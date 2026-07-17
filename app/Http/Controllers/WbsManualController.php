<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\WbsManual;
use App\Support\SmartCompanyData;
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
        $request->validate([
            'manual' => 'required|file|mimes:pdf,png,jpg,jpeg,webp|max:32768', // 32MB
            'project_code' => 'required|string|max:80',
            'site_id' => 'nullable',
        ]);

        try {
            $file = $request->file('manual');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

            $projectCode = (string) $request->input('project_code');
            $siteRowId = $this->resolveSiteId($request->input('site_id'));
            $siteScope = $this->resolveSiteScope($request->input('site_id'));

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

            $pdf = [
                'data' => base64_encode((string) file_get_contents($absolutePath)),
                'media_type' => $mime,
            ];

            $result = SmartCompanyData::analyzeWbsManual($projectCode, $siteScope, $pdf);

            if (! ($result['success'] ?? false)) {
                $manual->update(['status' => 'failed', 'error' => (string) ($result['error'] ?? 'AI 분석 실패')]);

                return response()->json(['success' => false, 'error' => $result['error'] ?? 'AI 분석 실패', 'manual' => $this->present($manual)], 422);
            }

            $row = $result['results'][0] ?? [];
            $manual->update([
                'status' => 'completed',
                'engine' => (string) ($row['engine'] ?? null),
                'stages' => (int) ($row['stages'] ?? 0),
                'tasks' => (int) ($row['tasks'] ?? 0),
                'subtasks' => (int) ($row['subTasks'] ?? 0),
                'analyzed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'manual' => $this->present($manual->fresh()),
                'result' => $result,
            ]);
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
        $query = WbsManual::query()->with('analyzedBy')->latest();

        if (filled($request->query('project'))) {
            $query->where('project_code', (string) $request->query('project'));
        }

        $manuals = $query->limit(50)->get()->map(fn (WbsManual $m) => $this->present($m))->all();

        return response()->json(['success' => true, 'manuals' => $manuals]);
    }

    /**
     * 저장된 매뉴얼 원본 파일 열람.
     */
    public function show(WbsManual $manual)
    {
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
