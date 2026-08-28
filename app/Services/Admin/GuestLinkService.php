<?php

namespace App\Services\Admin;

use App\Models\GuestLink;
use App\Models\Project;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * 손님 전용 링크 — 발급·회수와, 손님 화면이 보는 데이터 조립.
 *
 * 손님(발주처·방문객)에게 계정을 만들어 주지 않고 "지금 얼마나 왔나"만 보여 준다.
 * 원칙:
 *  - 링크 하나 = 현장 하나. 다른 현장은 주소 자체가 없다.
 *  - 보여 주는 것은 공정 현황(진척률·예상 준공·단계별 진행)뿐이다.
 *    돈(원가·경비·기성)과 사람(작업자·연락처)은 이 화면의 세계에 존재하지 않는다 —
 *    화면에서 숨기는 게 아니라 데이터 조립 단계에서 아예 뽑지 않는다.
 *  - 회수 가능: 링크는 전달되는 순간 복제되므로, 잘못 퍼졌으면 서버에서 끊는다.
 */
class GuestLinkService
{
    /** 링크를 발급·회수할 수 있는 역할 — 현장을 책임지는 사람들이다. */
    public const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager'];

    public function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->account_status === 'active'
            && in_array($user->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * 현장의 링크 목록. siteCode 'ALL' 이면 전체.
     *
     * @return array<string, mixed>
     */
    public function list(string $siteCode = 'ALL'): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '손님 링크는 관리자·현장소장만 관리할 수 있습니다.'];
        }

        $query = GuestLink::query()->with('site')->orderByDesc('id');
        if ($siteCode !== '' && $siteCode !== 'ALL') {
            $query->whereHas('site', fn ($q) => $q->where('code', $siteCode));
        }

        $links = $query->limit(100)->get()->map(fn (GuestLink $link): array => [
            'id' => $link->id,
            'siteCode' => $link->site?->code,
            'siteName' => $link->site?->name,
            'label' => $link->label,
            'url' => $link->url(),
            'qrUrl' => route('guest-link.qr', ['link' => $link->id]),
            'expiresAt' => $link->expires_at?->toDateString(),
            'revoked' => $link->revoked_at !== null,
            'usable' => $link->isUsable(),
            'viewCount' => $link->view_count,
            'lastViewedAt' => $link->last_viewed_at?->toDateTimeString(),
            'createdAt' => $link->created_at?->toDateString(),
        ])->values()->all();

        return ['success' => true, 'links' => $links];
    }

    /**
     * 발급. days 가 null 이면 무기한 — 무기한은 화면에서 고른 사람의 명시적 선택이다.
     *
     * @return array<string, mixed>
     */
    public function create(string $siteCode, ?string $label = null, ?int $days = null): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '손님 링크는 관리자·현장소장만 만들 수 있습니다.'];
        }

        $site = Site::query()->where('code', $siteCode)->first();
        if (! $site) {
            return ['success' => false, 'error' => "현장을 찾을 수 없습니다: {$siteCode}"];
        }

        $link = GuestLink::issue($site->id, $label, $days, Auth::id());

        return [
            'success' => true,
            'id' => $link->id,
            'url' => $link->url(),
            'qrUrl' => route('guest-link.qr', ['link' => $link->id]),
            'expiresAt' => $link->expires_at?->toDateString(),
        ];
    }

    /**
     * 회수 — 이미 퍼진 링크를 그 자리에서 끊는다. 지우지 않고 도장을 찍는 이유는
     * "언제 누구에게 준 링크를 언제 끊었나"가 남아야 하기 때문이다.
     *
     * @return array<string, mixed>
     */
    public function revoke(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '손님 링크는 관리자·현장소장만 회수할 수 있습니다.'];
        }

        $link = GuestLink::query()->find($id);
        if (! $link) {
            return ['success' => false, 'error' => '링크를 찾을 수 없습니다.'];
        }

        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);
        }

        return ['success' => true];
    }

    /**
     * 손님 화면에 보여 줄 것 전부. 여기 없는 것은 화면에도 없다.
     *
     * 진척률은 WbsService::weightedProgress(진척률의 정본)로 계산해 ERP 안의 숫자와
     * 같은 값이 나온다 — 손님에게 다른 숫자를 보여 주면 그 차이를 설명하는 일이 생긴다.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Site $site): array
    {
        $wbs = app(WbsService::class);
        $allSubtasks = collect();
        $lastUpdated = null;

        $projects = Project::query()
            ->where('site_id', $site->id)
            ->orderBy('project_code')
            ->get()
            ->map(function (Project $project) use ($wbs, &$allSubtasks, &$lastUpdated): ?array {
                $items = WbsItem::query()
                    ->where('project_code', $project->project_code)
                    ->orderBy('sort_order')
                    ->get();
                if ($items->isEmpty()) {
                    return null;
                }

                $subtasks = $items->where('level', WbsItem::LEVEL_SUBTASK);
                $allSubtasks = $allSubtasks->concat($subtasks);
                $updated = $items->max('updated_at');
                if ($updated !== null && ($lastUpdated === null || $updated->gt($lastUpdated))) {
                    $lastUpdated = $updated;
                }

                $byParent = $items->groupBy('parent_id');
                $descendants = function (WbsItem $node) use ($byParent, &$descendants): Collection {
                    $children = $byParent[$node->id] ?? collect();

                    return $children->flatMap(fn (WbsItem $c) => $c->level === WbsItem::LEVEL_SUBTASK
                        ? collect([$c])
                        : $descendants($c));
                };

                $stages = $items->where('level', WbsItem::LEVEL_STAGE)->values()->map(function (WbsItem $stage) use ($wbs, $descendants): array {
                    $subs = $descendants($stage);
                    $progress = $wbs->weightedProgress($subs);

                    return [
                        'name' => $stage->name,
                        'progress' => $progress,
                        'state' => $progress >= 100 ? 'done' : ($progress > 0 ? 'active' : 'planned'),
                    ];
                })->values()->all();

                return [
                    'name' => $project->name,
                    'progress' => $wbs->weightedProgress($subtasks),
                    'projectedEnd' => $subtasks->map(fn (WbsItem $i) => $i->planned_end?->toDateString())->filter()->max(),
                    'doneCount' => $subtasks->where('status', WbsItem::STATUS_DONE)->count(),
                    'totalCount' => $subtasks->count(),
                    'stages' => $stages,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'siteCode' => $site->code,
            'siteName' => $site->name,
            'progress' => $wbs->weightedProgress($allSubtasks),
            'projectedEnd' => collect($projects)->pluck('projectedEnd')->filter()->max(),
            'updatedAt' => $lastUpdated?->toDateString(),
            'projects' => $projects,
        ];
    }
}
