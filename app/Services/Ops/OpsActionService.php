<?php

namespace App\Services\Ops;

use App\Models\OpsActionItem;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 오늘 한 일 · 내일 할 일 — 액션 아이템을 시점별로 갈라 보여준다.
 *
 * 현장에서 실제로 필요한 질문은 셋이다.
 *   1. 오늘 뭐 했나        → 오늘 완료된 것
 *   2. 내일 뭘 준비해야 하나 → 기한이 내일이거나 없는 미완
 *   3. 뭐가 늦었나          → 기한이 지난 미완
 *
 * 막힘(is_blocker)은 항상 맨 위로 올린다. "화기작업 승인" 이 안 되면 커팅 자체를 못 하므로,
 * 다른 열 건보다 이 한 건이 먼저 보여야 한다.
 */
class OpsActionService
{
    /**
     * @return array<string, mixed>
     */
    public function board(?int $siteId, ?string $date = null): array
    {
        $site = $siteId ? Site::find($siteId) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $today = $date ?: Carbon::now($tz)->toDateString();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $open = OpsActionItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('status', 'open')
            ->orderByDesc('is_blocker')
            ->orderByRaw('due_on is null, due_on')
            ->get();

        $doneToday = OpsActionItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('status', 'done')
            ->whereDate('done_at', $today)
            ->orderByDesc('done_at')
            ->get();

        return [
            'success' => true,
            'date' => $today,
            'overdue' => $this->rows($open->filter(fn (OpsActionItem $a) => $a->due_on && $a->due_on->toDateString() < $today)),
            'today' => $this->rows($open->filter(fn (OpsActionItem $a) => $a->due_on && $a->due_on->toDateString() === $today)),
            'tomorrow' => $this->rows($open->filter(fn (OpsActionItem $a) => $a->due_on && $a->due_on->toDateString() === $tomorrow)),
            // 기한이 없는 것 — 놓치기 쉬우므로 따로 모아 보여준다.
            'undated' => $this->rows($open->filter(fn (OpsActionItem $a) => $a->due_on === null)),
            'later' => $this->rows($open->filter(fn (OpsActionItem $a) => $a->due_on && $a->due_on->toDateString() > $tomorrow)),
            'doneToday' => $this->rows($doneToday),
            'blockers' => $this->rows($open->where('is_blocker', true)),
            'openTotal' => $open->count(),
        ];
    }

    /**
     * @param  Collection<int, OpsActionItem>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows($rows): array
    {
        return $rows->map(fn (OpsActionItem $a): array => [
            'id' => $a->id,
            'kind' => $a->kind,
            'kindLabel' => $a->kindLabel(),
            'title' => $a->title,
            'detail' => $a->detail,
            'requester' => $a->requester,
            'assignee' => $a->assignee,
            'dueOn' => $a->due_on?->toDateString(),
            'isBlocker' => $a->is_blocker,
            'status' => $a->status,
            'fromOps' => $a->ops_intake_batch_id !== null,
        ])->values()->all();
    }

    /**
     * 관리자가 직접 추가한다(AI 가 놓친 것, 또는 회의에서 나온 것).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(?int $siteId, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => '할 일을 입력하세요.'];
        }

        $kind = (string) ($input['kind'] ?? OpsActionItem::KIND_TODO);
        $kind = array_key_exists($kind, OpsActionItem::KIND_LABELS) ? $kind : OpsActionItem::KIND_TODO;

        $due = trim((string) ($input['dueOn'] ?? ''));

        $row = OpsActionItem::query()->updateOrCreate(
            ['id' => (int) ($input['id'] ?? 0) ?: null],
            [
                'site_id' => $siteId,
                'kind' => $kind,
                'title' => mb_substr($title, 0, 255),
                'detail' => trim((string) ($input['detail'] ?? '')) ?: null,
                'requester' => trim((string) ($input['requester'] ?? '')) ?: null,
                'assignee' => trim((string) ($input['assignee'] ?? '')) ?: null,
                'due_on' => $due !== '' ? Carbon::parse($due)->toDateString() : null,
                'is_blocker' => (bool) ($input['isBlocker'] ?? false),
                'status' => 'open',
            ],
        );

        return ['success' => true, 'id' => $row->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(int $id, ?int $userId = null): array
    {
        $row = OpsActionItem::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '항목을 찾을 수 없습니다.'];
        }

        // 이미 완료한 것을 다시 누르면 되돌린다(잘못 눌렀을 때 복구할 방법이 있어야 한다).
        $done = $row->status !== 'done';
        $row->update([
            'status' => $done ? 'done' : 'open',
            'done_at' => $done ? now() : null,
            'done_by_id' => $done ? $userId : null,
        ]);

        return ['success' => true, 'status' => $row->status];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $row = OpsActionItem::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '항목을 찾을 수 없습니다.'];
        }
        $row->delete();

        return ['success' => true];
    }
}
