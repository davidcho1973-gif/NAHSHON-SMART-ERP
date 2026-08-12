<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Wbs\ScheduleImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 공정표 엑셀을 화면에서 교체한다.
 *
 * 지금까지는 서버 터미널(wbs:clear + wbs:import-schedule)에서만 가능했다. 공정표는 현장에서
 * 자주 바뀌는데 그때마다 서버에 접속해야 한다면 결국 아무도 안 바꾸고, 화면의 공정표는
 * 엑셀과 조용히 어긋난 채로 남는다.
 *
 * 교체는 되돌리기 어렵다 — 기존 트리와 거기 딸린 안전카드가 지워진다. 그래서 두 단계로 나눈다:
 *
 *   1) preview  : 저장하지 않고 읽기만 해서 몇 개가 읽혔는지, 무엇이 지워질지 보여 준다
 *   2) replace  : 그 결과를 보고 확인한 뒤에만 실제로 교체한다
 *
 * 헤더 이름이 하나만 달라도 액티비티가 0 개로 읽힌다. 그 상태로 바로 교체하면 공정표가
 * 통째로 날아간다. preview 를 건너뛸 수 없게 만든 이유다.
 */
class WbsScheduleController extends Controller
{
    /** 공정표를 갈아끼울 수 있는 역할. 공정표는 현장 전체 계획이라 아무나 바꾸면 안 된다. */
    private const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager'];

    /** 현장 제한 없이 전체를 다루는 역할. */
    private const GLOBAL_ROLES = ['super_admin', 'admin'];

    /** 미리보기 파일을 붙잡아 두는 시간. 확인하는 데 이 정도면 충분하고, 남아 쌓이지도 않는다. */
    private const PREVIEW_TTL_MINUTES = 30;

    /**
     * 1단계 — 읽기만 한다. DB 는 건드리지 않는다.
     */
    public function preview(Request $request, ScheduleImporter $importer): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate([
            'schedule' => 'required|file|mimes:xlsx|max:32768', // 32MB
            'project_code' => 'required|string|max:80',
            'site_id' => 'nullable',
        ]);

        $projectCode = (string) $request->input('project_code');
        $project = Project::query()->where('project_code', $projectCode)->first();
        if (! $project) {
            return response()->json(['success' => false, 'error' => "프로젝트를 찾을 수 없습니다: {$projectCode}"], 404);
        }

        $this->authorizeSite($request, $project->site_id);

        $file = $request->file('schedule');
        // 미리보기와 교체가 반드시 같은 파일이어야 한다. 사용자가 다시 고르게 하면
        // 확인한 것과 다른 파일이 들어갈 수 있다.
        $stored = $file->store('wbs-schedules', 'local');

        try {
            $read = $importer->preview(Storage::disk('local')->path($stored));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'token' => $stored,
            'expiresInMinutes' => self::PREVIEW_TTL_MINUTES,
            'fileName' => $file->getClientOriginalName(),
            'read' => $read,
            // 무엇이 유지되고 무엇이 사라지는지. 숫자를 보고 결정해야 한다.
            'willDelete' => $this->replacementImpact($projectCode, $read['activityIds'] ?? []),
            'blocked' => $read['activities'] === 0
                ? '액티비티를 하나도 읽지 못했습니다. 헤더 이름(ID / 작업명 / 공기)을 확인하세요. 이대로 교체하면 공정표가 비게 됩니다.'
                : null,
        ]);
    }

    /**
     * 2단계 — 미리보기한 그 파일로 실제 교체.
     */
    public function replace(Request $request, ScheduleImporter $importer): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate([
            'token' => 'required|string|max:255',
            'project_code' => 'required|string|max:80',
            'confirm' => 'required|accepted',
            'site_id' => 'nullable',
        ]);

        $projectCode = (string) $request->input('project_code');
        $project = Project::query()->where('project_code', $projectCode)->first();
        if (! $project) {
            return response()->json(['success' => false, 'error' => "프로젝트를 찾을 수 없습니다: {$projectCode}"], 404);
        }

        $this->authorizeSite($request, $project->site_id);

        // 토큰은 우리가 발급한 경로여야 한다. 임의 경로를 받으면 서버의 아무 파일이나 읽게 된다.
        $token = (string) $request->input('token');
        if (! str_starts_with($token, 'wbs-schedules/') || ! Storage::disk('local')->exists($token)) {
            return response()->json(['success' => false, 'error' => '미리보기가 만료되었습니다. 파일을 다시 올려 주세요.'], 410);
        }

        $path = Storage::disk('local')->path($token);

        // 교체 전에 한 번 더 읽는다. 0 개면 지우기만 하고 끝나므로 여기서 멈춘다.
        try {
            $check = $importer->preview($path);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        if ($check['activities'] === 0) {
            return response()->json([
                'success' => false,
                'error' => '액티비티를 하나도 읽지 못해 교체를 중단했습니다. 기존 공정표는 그대로입니다.',
            ], 422);
        }

        $siteScope = $this->resolveSiteScope($request->input('site_id'));

        try {
            // 트리는 임포터가 갈아끼운다 — 직접 지우면 안 된다.
            // 임포터는 activity_id 로 현장이 올린 진행률·협력사 배정을 물려받는데,
            // 여기서 먼저 지워 버리면 물려받을 것이 없어져 개정 때마다 실적이 날아간다.
            // 공정표는 Rev.2 → Rev.3 로 계속 갱신되므로 이 손실은 매번 반복된다.
            $result = $importer->importFromXlsx($path, $projectCode, $siteScope);

            // 새 공정표에서 사라진 작업의 안전카드만 정리한다. 남아 있는 작업은
            // wbs_code 가 그대로라 카드도 그대로 붙어 있다.
            $removed = $this->pruneOrphanedSafetyCards($projectCode);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => '교체 중 오류: '.$e->getMessage()], 500);
        } finally {
            Storage::disk('local')->delete($token);
        }

        return response()->json([
            'success' => true,
            'removed' => $removed,
            'imported' => $result,
        ]);
    }

    /**
     * 교체하면 무엇이 유지되고 무엇이 사라지는지 미리 센다.
     *
     * 전부 지웠다 새로 넣는 것이 아니다 — 액티비티 ID 가 같은 작업은 현장이 올린
     * 진행률·협력사 배정을 그대로 물려받는다. 그래서 "지워질 N개" 가 아니라
     * "유지 N개 / 사라짐 M개" 로 보여 줘야 사실과 맞는다.
     *
     * TBM 서명은 법적 기록이라 따로 세어 보여 준다. 사라진 뒤에 알게 되면 늦다.
     *
     * @param  array<int, string>  $incomingIds  새 시트에 들어 있는 액티비티 ID
     * @return array{wbsItems: int, kept: int, safetyCards: int, signatures: int, issues: int}
     */
    private function replacementImpact(string $projectCode, array $incomingIds): array
    {
        $current = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->get(['activity_id', 'wbs_code']);

        $incoming = array_flip($incomingIds);
        $gone = $current->reject(fn (WbsItem $i) => isset($incoming[(string) $i->activity_id]));

        $orphanIds = SafetyWorkItem::query()
            ->whereIn('wbs_code', $gone->pluck('wbs_code'))
            ->pluck('id');

        return [
            'wbsItems' => $gone->count(),
            'kept' => $current->count() - $gone->count(),
            'safetyCards' => $orphanIds->count(),
            'signatures' => SafetyWorkSignature::query()->whereIn('safety_work_item_id', $orphanIds)->count(),
            'issues' => SafetyWorkIssue::query()->whereIn('safety_work_item_id', $orphanIds)->count(),
        ];
    }

    /**
     * 교체 뒤, 새 공정표에 없는 작업에 붙어 있던 안전카드를 정리한다.
     *
     * 살아남은 작업은 wbs_code 가 그대로라(프로젝트코드-W-액티비티ID) 카드도 그대로 붙어 있다.
     * 없어진 작업의 카드만 고아가 되므로 그것만 지운다 — 전부 지우면 현장이 이미 서명한
     * TBM 기록까지 함께 사라진다.
     *
     * 삭제 순서는 wbs:clear 와 같다: 자식(서명·이슈) → 안전카드.
     *
     * @return array{wbsItems: int, safetyCards: int, signatures: int, issues: int}
     */
    private function pruneOrphanedSafetyCards(string $projectCode): array
    {
        $live = WbsItem::query()->where('project_code', $projectCode)->pluck('wbs_code');

        $orphanIds = SafetyWorkItem::query()
            ->where('wbs_code', 'like', $projectCode.'-W-%')
            ->whereNotIn('wbs_code', $live)
            ->pluck('id');

        $counts = [
            'wbsItems' => 0,
            'safetyCards' => $orphanIds->count(),
            'signatures' => SafetyWorkSignature::query()->whereIn('safety_work_item_id', $orphanIds)->count(),
            'issues' => SafetyWorkIssue::query()->whereIn('safety_work_item_id', $orphanIds)->count(),
        ];

        if ($orphanIds->isNotEmpty()) {
            DB::transaction(function () use ($orphanIds): void {
                SafetyWorkSignature::query()->whereIn('safety_work_item_id', $orphanIds)->delete();
                SafetyWorkIssue::query()->whereIn('safety_work_item_id', $orphanIds)->delete();
                SafetyWorkItem::query()->whereIn('id', $orphanIds)->delete();
            });
        }

        return $counts;
    }

    // ── 권한 ────────────────────────────────────────────────────────────
    //
    // 프론트에서 버튼을 숨기는 것은 권한 경계가 아니다. 요청마다 서버에서 막는다.

    private function authorizeManage(Request $request): void
    {
        $role = (string) (optional($request->user())->access_role ?? '');
        abort_unless(in_array($role, self::MANAGE_ROLES, true), 403, '공정표를 교체할 권한이 없습니다.');
    }

    private function authorizeSite(Request $request, ?int $siteRowId): void
    {
        $user = $request->user();
        $role = (string) ($user->access_role ?? '');

        if (in_array($role, self::GLOBAL_ROLES, true)) {
            return;
        }

        $allowed = $user->allowed_site_id ?? null;
        abort_unless($allowed && $siteRowId && (int) $allowed === (int) $siteRowId, 403, '이 현장의 공정표를 바꿀 권한이 없습니다.');
    }

    private function resolveSiteScope(mixed $siteId): string
    {
        $value = is_string($siteId) ? trim($siteId) : '';
        if ($value === '' || $value === 'ALL') {
            return 'ALL';
        }

        return Site::query()->where('code', $value)->exists() ? $value : 'ALL';
    }
}
