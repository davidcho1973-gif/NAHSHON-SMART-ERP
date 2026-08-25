<?php

namespace App\Console\Commands;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\ProcurementItem;
use App\Models\WbsItem;
use Illuminate\Console\Command;
use Throwable;

/**
 * 아침 브리핑 — "오늘 가장 위험한 3가지"를 영향도 순으로 방에 올린다.
 *
 * 대시보드에 스무 개 숫자가 있어도 아침에 필요한 것은 "무엇부터 봐야 하나" 셋이다.
 * 영향도 순서: ① 준공을 쥔 작업(임계경로)이 늦고 있다 ② 자재가 안 와서 곧 막힌다
 * ③ 오늘 작업인데 안전계획(TBM)이 없다. 셋 다 없으면 조용히 넘어간다 —
 * 매일 오는 "이상 없음"은 사흘이면 아무도 안 읽는다.
 */
class MorningBrief extends Command
{
    protected $signature = 'ops:morning-brief';

    protected $description = '오늘 가장 위험한 3가지를 현장 상황실 방에 게시합니다';

    public const BOT_MARKER = 'morning_brief';

    public function handle(): int
    {
        $rooms = CommunicationRoom::query()
            ->where('type', CommunicationRoom::TYPE_SITE_OPS)
            ->where('status', 'active')
            ->whereNotNull('site_id')
            ->get();

        foreach ($rooms as $room) {
            try {
                $risks = $this->risksFor((int) $room->site_id);
                if ($risks === []) {
                    continue;
                }
                $this->post($room, $risks);
                $this->line("{$room->name}: ".count($risks).'건 게시');
            } catch (Throwable $e) {
                report($e);
                $this->error("{$room->name}: 브리핑 실패 — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * 영향도 순 상위 3개 — 준공 지연 > 자재 병목 > 안전계획 공백.
     *
     * @return array<int, string>
     */
    private function risksFor(int $siteId): array
    {
        $today = now()->toDateString();
        $risks = [];

        // ① 임계경로 작업이 늦고 있다 — 이 지연은 그대로 준공 지연이다(CPM 엔진 검증).
        WbsItem::query()
            ->where('site_id', $siteId)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->where('is_critical', true)
            ->where('status', '!=', WbsItem::STATUS_DONE)
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', $today)
            ->orderBy('planned_end')
            ->limit(2)
            ->get()
            ->each(function (WbsItem $i) use (&$risks, $today): void {
                $days = (int) $i->planned_end->diffInDays($today);
                $risks[] = "🔴 준공 직결: '{$i->name}' 종료 예정이 {$days}일 지났습니다 — 임계경로라 이 지연이 그대로 준공 지연입니다.";
            });

        // ② 자재가 안 와서 곧 막힌다.
        ProcurementItem::query()
            ->where('site_id', $siteId)
            ->where('status', '!=', '입고완료')
            ->whereNotNull('eta')
            ->where('eta', '<', $today)
            ->orderBy('eta')
            ->limit(2)
            ->get()
            ->each(function (ProcurementItem $po) use (&$risks, $today): void {
                $days = (int) $po->eta->diffInDays($today);
                $risks[] = '🟠 자재 병목: '.($po->po_no ? "PO {$po->po_no}" : '발주 건')." ETA 가 {$days}일 지났는데 아직 {$po->status} 입니다.";
            });

        // ③ 오늘 시작해야 하는 인원 투입 작업인데 안전카드가 없다 — TBM 없이는 시작 못 한다.
        WbsItem::query()
            ->where('site_id', $siteId)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->where('status', '!=', WbsItem::STATUS_DONE)
            ->where('crew_size', '>', 0)
            ->whereDate('planned_start', '<=', $today)
            ->whereDate('planned_end', '>=', $today)
            ->whereDoesntHave('safetyWorkItems', fn ($q) => $q->whereDate('work_date', $today))
            ->limit(2)
            ->get()
            ->each(function (WbsItem $i) use (&$risks): void {
                $risks[] = "🟡 안전계획 공백: 오늘 작업 '{$i->name}' 의 안전카드가 아직 없습니다 — TBM 없이는 시작할 수 없습니다.";
            });

        return array_slice($risks, 0, 3);
    }

    /** @param array<int, string> $risks */
    private function post(CommunicationRoom $room, array $risks): void
    {
        $body = "오늘 가장 위험한 ".count($risks)."가지 — 영향이 큰 순서입니다.\n".implode("\n", $risks);

        $message = CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'company_id' => $room->company_id,
            'site_id' => $room->site_id,
            'sender_user_id' => null,
            'sender_employee_id' => null,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '🌅 아침 브리핑',
            'body' => mb_substr($body, 0, 2000),
            'status' => 'active',
            'priority' => 'high',
            'payload' => ['bot' => self::BOT_MARKER, 'date' => now()->toDateString()],
        ]);

        try {
            app(\App\Services\Push\ChatPushNotifier::class)->notify($message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
