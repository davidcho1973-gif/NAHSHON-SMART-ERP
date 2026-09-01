<?php

namespace App\Services\Ops;

use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Site;
use App\Models\User;
use App\Services\Push\WebPushSender;
use Illuminate\Support\Carbon;

/**
 * 미제출 보고 알림 — "오늘 배관 보고가 아직 없습니다".
 *
 * 출퇴근 알림과 같은 규칙을 쓴다: 시간대 밖에서는 아무것도 하지 않고, 하루 한 번만
 * 묻고, 낸 사람에게는 울리지 않는다. 다른 점은 대상이 <b>사람</b>이 아니라
 * <b>공종</b>이라는 것이다 — 같은 공종의 반장이 둘이면 둘 다에게 간다(보고는 하나지만
 * 낼 수 있는 사람은 여럿이다).
 *
 * 그리고 알림을 무시한 채 마감 시각이 지나면 <b>소장에게</b> 올린다. 미퇴근 알림과
 * 같은 이유다 — 푸시는 폰이 꺼져 있으면 닿지 않고, 닿아도 무시할 수 있다.
 */
class TradeReportReminderService
{
    /** 마감 시각 몇 분 전에 묻는가. 미리 물어야 아직 현장에 있을 때 올린다. */
    private const NUDGE_BEFORE_MINUTES = 60;

    public function __construct(
        private readonly WebPushSender $push,
        private readonly TradeReportService $reports,
    ) {}

    /**
     * @return array{checked: int, sent: int, escalated: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $checked = 0;
        $sent = 0;
        $escalated = 0;

        foreach (Site::query()->where('status', 'active')->get() as $site) {
            $tz = $site->timezone ?: config('app.timezone');
            $local = $now->copy()->timezone($tz);
            $workDate = $local->toDateString();

            $due = $local->copy()->setTime($this->reports->dueHour(), 0, 0);
            $nudgeFrom = $due->copy()->subMinutes(self::NUDGE_BEFORE_MINUTES);

            // 마감 한 시간 전부터 묻고, 마감이 지나면 소장에게 올린다.
            if ($local->lessThan($nudgeFrom)) {
                continue;
            }

            $missing = $this->missingTrades($site->id, $workDate);
            $checked += count($missing);

            if ($missing === []) {
                continue;
            }

            if ($local->lessThan($due)) {
                $sent += $this->nudge($site, $workDate, $missing);

                continue;
            }

            $escalated += $this->escalate($site, $workDate, $missing) ? 1 : 0;
        }

        return ['checked' => $checked, 'sent' => $sent, 'escalated' => $escalated];
    }

    /**
     * 오늘 일했는데 아직 안 낸 공종.
     *
     * @return array<int, string>
     */
    public function missingTrades(int $siteId, string $workDate): array
    {
        $expected = $this->reports->expectedTrades($siteId, $workDate);
        if ($expected === []) {
            return [];   // 아무도 안 나온 날 — 물을 것이 없다.
        }

        $submitted = DailyTradeReport::query()
            ->where('site_id', $siteId)
            ->where('work_date', $workDate)
            ->where('status', DailyTradeReport::STATUS_SUBMITTED)
            ->pluck('trade')
            ->all();

        return array_values(array_diff($expected, $submitted));
    }

    /**
     * 그 공종의 반장들에게 묻는다.
     *
     * @param  array<int, string>  $trades
     */
    private function nudge(Site $site, string $workDate, array $trades): int
    {
        $sent = 0;

        foreach ($trades as $trade) {
            $users = $this->foremenOf($site->id, $trade);
            if ($users === []) {
                continue;   // 알림을 켠 반장이 없다 — 마감 뒤 소장 알림으로 걸린다.
            }

            $delivered = $this->push->sendToUsers($users, [
                'title' => '🔔 오늘 '.$trade.' 보고가 아직 없습니다',
                'body' => $site->code.' · 사진과 오늘 한 일을 올리고 「오늘 보고 제출」을 눌러 주세요.',
                'url' => route('attendance-app.ops-room', absolute: false),
                'tag' => 'trade-report:'.$trade,
            ]);

            if ($delivered > 0) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 마감이 지나도 안 낸 공종 — 소장에게 이름과 함께 올린다.
     *
     * @param  array<int, string>  $trades
     */
    private function escalate(Site $site, string $workDate, array $trades): bool
    {
        try {
            app(\App\Services\Alerts\UnifiedAlertService::class)->emit(
                "trade-report-missing:{$site->id}:{$workDate}",
                [
                    'company_id' => $site->company_id,
                    'site_id' => $site->id,
                    'source_module' => 'OPS',
                    'source_type' => Site::class,
                    'source_id' => (string) $site->id,
                    'event_type' => 'trade_report_missing',
                    'severity' => 'warning',
                    'title' => sprintf('%s 오늘 보고 미제출 %d개 공종 (%s)', $site->code, count($trades), $workDate),
                    'content' => sprintf(
                        "%s\n\n이 공종들은 오늘 사람이 나왔는데 보고가 없습니다. "
                        .'이대로 마감하면 종합보고서에서 그 공종이 통째로 빠진 채 원청에 나갑니다.',
                        implode(', ', $trades),
                    ),
                    'action_url' => '/?view=opsroom',
                ],
            );

            return true;
        } catch (\Throwable $e) {
            report($e); // 알림 실패가 마감을 막으면 안 된다.

            return false;
        }
    }

    /**
     * 그 공종에서 보고를 낼 수 있는 사람 — 알림을 켠 사람만.
     *
     * @return array<int, User>
     */
    private function foremenOf(int $siteId, string $trade): array
    {
        $userIds = PushSubscription::query()->distinct()->pluck('user_id');

        $employeeIds = Employee::query()
            ->where('site_id', $siteId)
            ->where('role', $trade)
            ->where('employment_status', 'active')
            ->pluck('id');

        return User::query()
            ->whereIn('id', $userIds)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->all();
    }
}
