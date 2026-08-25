<?php

namespace App\Console\Commands;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\WbsItem;
use App\Services\Wbs\WeeklyPlanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 매주 월요일 아침 — 지난주 PPC 집계 + 이번 주 약속 제안 + 방에 요약.
 *
 * 요약이 방(K-TALK)으로 가는 이유: 화면을 열어야 보이는 숫자는 리듬이 되지 않는다.
 * 월요일 아침 방에 "지난주 72%, 자재 지연이 최다" 한 장이 도착해야 회의가 그걸로 시작한다.
 */
class WeeklyPlanCommand extends Command
{
    protected $signature = 'wbs:weekly-plan {project? : 프로젝트 코드 (생략하면 전체)}';

    protected $description = '주간 계획 — 지난주 PPC 집계, 이번 주 약속 제안, 상황실 방 요약';

    public const BOT_MARKER = 'weekly_plan';

    public function handle(WeeklyPlanService $service): int
    {
        $codes = $this->argument('project')
            ? [(string) $this->argument('project')]
            : WbsItem::query()->whereNotNull('project_code')->distinct()->pluck('project_code')->all();

        foreach ($codes as $code) {
            try {
                $review = $service->weeklyReview((string) $code);
                $this->line("{$code}: PPC ".($review['ppc']['ppc'] ?? '-').'% · 이번 주 약속 '.$review['committedCount'].'건');
                $this->postToRoom((string) $code, (string) $review['summary']);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$code}: 주간 계획 실패 — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * 프로젝트의 현장 상황실 방에 요약을 남긴다. 방이 없으면 조용히 넘어간다 —
     * 요약은 화면(주간 계획 패널)에도 있으므로 잃어버리는 것은 아니다.
     */
    private function postToRoom(string $projectCode, string $summary): void
    {
        $siteId = WbsItem::query()
            ->where('project_code', $projectCode)
            ->whereNotNull('site_id')
            ->value('site_id');
        if ($siteId === null) {
            return;
        }

        $room = CommunicationRoom::query()
            ->where('type', CommunicationRoom::TYPE_SITE_OPS)
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->first();
        if ($room === null) {
            return;
        }

        $message = CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'sender_user_id' => null,
            'sender_employee_id' => null,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '📅 주간 계획',
            'body' => mb_substr($summary, 0, 2000),
            'status' => 'active',
            'priority' => 'normal',
            'payload' => ['bot' => self::BOT_MARKER, 'project_code' => $projectCode],
        ]);

        try {
            app(\App\Services\Push\ChatPushNotifier::class)->notify($message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
