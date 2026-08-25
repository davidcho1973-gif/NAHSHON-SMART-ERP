<?php

namespace App\Services\Ops;

use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\OpsIntakeItem;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 하루 다이제스트 — 그날 상황실에서 뽑아낸 것과 처리 결과를 한 장으로 정리해 알린다.
 *
 * "오늘 14건 인식 → 9건 반영, 3건 확인 필요" 를 저녁에 한 번 보고, 남은 것만 정리하면 끝나게 한다.
 */
class OpsDigestService
{
    private const MANAGER_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    /**
     * 하루 집계.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $siteId = null, ?Carbon $date = null): array
    {
        $date ??= Carbon::today();
        $rows = OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('created_at', $date->toDateString())
            ->get();

        $actionable = $rows->where('category', '!=', 'noise');
        $needs = $actionable->whereIn('status', ['needs_input']);
        $pending = $actionable->where('status', 'pending');

        $byCategory = $actionable->groupBy('category')->map->count();

        return [
            'success' => true,
            'date' => $date->toDateString(),
            'parsed' => $rows->count(),
            'actionable' => $actionable->count(),
            'noise' => $rows->where('category', 'noise')->count(),
            'applied' => $actionable->where('status', 'applied')->count(),
            'pending' => $pending->count(),
            'needsInput' => $needs->count(),
            'dismissed' => $actionable->where('status', 'dismissed')->count(),
            'byCategory' => $byCategory->all(),
            'needsItems' => $needs->take(10)->map(fn (OpsIntakeItem $i) => [
                'id' => $i->id,
                'summary' => $i->summary,
                'question' => $i->question,
                'raw' => mb_substr((string) $i->raw_text, 0, 120),
            ])->values()->all(),
        ];
    }

    /**
     * 다이제스트를 상황실 방에 게시하고 관리자에게 알린다.
     *
     * @return array{sites: int, posted: int, notified: int}
     */
    public function dispatchDigest(?Carbon $date = null): array
    {
        $date ??= Carbon::today();
        $sites = Site::query()->where('status', 'active')->get();

        $posted = 0;
        $notified = 0;
        foreach ($sites as $site) {
            $s = $this->summary($site->id, $date);
            if ($s['actionable'] === 0) {
                continue; // 그날 건진 게 없으면 조용히 넘어간다.
            }

            $body = $this->body($s);

            $room = CommunicationRoom::query()
                ->where('site_id', $site->id)
                ->where('type', CommunicationRoom::TYPE_SITE_OPS)->first();

            if ($room) {
                CommunicationMessage::query()->create([
                    'communication_room_id' => $room->id,
                    'kind' => CommunicationMessage::KIND_SYSTEM,
                    'title' => sprintf('📋 %s 상황실 하루 요약', $date->format('n/j')),
                    'body' => mb_substr($body, 0, 2000),
                    'status' => 'active',
                    'priority' => $s['needsInput'] > 0 ? 'high' : 'normal',
                    'payload' => ['bot' => OpsRoomAutoReader::BOT_MARKER, 'digest' => true],
                ]);
                $posted++;
            }

            // 방 멤버인 관리자는 방 게시로 이미 받는다 — 종(개인 알림)까지 울리면
            // 같은 요약이 두 번 온다(연계 점검: 다이제스트 방+종 2번).
            $roomMemberUserIds = $room
                ? \App\Models\CommunicationRoomMember::query()
                    ->where('communication_room_id', $room->id)
                    ->where('status', 'active')
                    ->whereNotNull('user_id')
                    ->pluck('user_id')->all()
                : [];

            $title = sprintf('[상황실 요약] %s · 반영 %d · 확인필요 %d', $site->code, $s['applied'], $s['needsInput']);
            foreach ($this->managers($site->id) as $m) {
                if (in_array($m->id, $roomMemberUserIds, true)) {
                    continue;
                }
                $exists = CommunicationNotification::query()
                    ->where('user_id', $m->id)->where('type', 'ops_digest')
                    ->where('title', $title)->whereDate('created_at', $date->toDateString())->exists();
                if ($exists) {
                    continue;
                }
                CommunicationNotification::create([
                    'user_id' => $m->id,
                    'employee_id' => $m->employee_id,
                    'type' => 'ops_digest',
                    'title' => mb_substr($title, 0, 255),
                    'body' => mb_substr(sprintf('오늘 %d건 인식 · 반영 %d · 확인필요 %d', $s['actionable'], $s['applied'], $s['needsInput']), 0, 255),
                ]);
                $notified++;
            }
        }

        return ['sites' => $sites->count(), 'posted' => $posted, 'notified' => $notified];
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function body(array $s): string
    {
        $lines = [
            sprintf('오늘 상황실에서 %d건을 읽어 업무 %d건을 뽑았습니다. (잡담 %d건 제외)', $s['parsed'], $s['actionable'], $s['noise']),
            sprintf('✅ 반영 %d건 · ⏳ 대기 %d건 · ❓ 확인필요 %d건', $s['applied'], $s['pending'], $s['needsInput']),
        ];

        if ($s['byCategory'] !== []) {
            $parts = [];
            foreach ($s['byCategory'] as $cat => $n) {
                $parts[] = (OpsIntakeItem::CATEGORY_LABELS[$cat] ?? $cat)." {$n}";
            }
            $lines[] = '분류: '.implode(' · ', $parts);
        }

        if ($s['needsItems'] !== []) {
            $lines[] = '';
            $lines[] = '❓ 확인이 필요한 항목:';
            foreach ($s['needsItems'] as $i) {
                $lines[] = '  · '.($i['summary'] ?: $i['raw']).($i['question'] ? ' — '.$i['question'] : '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, User>
     */
    private function managers(int $siteId)
    {
        return User::query()
            ->whereIn('access_role', self::MANAGER_ROLES)
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get();
    }
}
