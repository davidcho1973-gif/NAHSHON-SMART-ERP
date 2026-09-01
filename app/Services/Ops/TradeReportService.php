<?php

namespace App\Services\Ops;

use App\Models\AttendanceLog;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 공종별 일일보고 — 반장의 몫을 만들고, 소장에게 누가 냈는지 보여 준다.
 *
 * ── 설계의 중심: <b>기대 목록을 출퇴근에서 뽑는다</b> ────────────────────
 * "오늘 어느 공종이 보고해야 하는가" 를 사람이 관리하게 하면 곧 안 맞는다. 대신
 * 오늘 그 현장에 <b>실제로 출근한 사람들의 공종</b>을 기대 목록으로 쓴다. 아무도
 * 안 온 공종에 보고를 요구하면 현황판이 영원히 빨갛고, 영원히 빨간 판은 아무도
 * 보지 않는다.
 *
 * ── 공종당 한 장 ──────────────────────────────────────────────────────
 * 반장이 둘이어도 보고는 하나다. 현장에서 "배관 오늘 보고" 는 한 장이지 사람 수
 * 만큼이 아니다. 그래서 같은 공종의 두 사람이 올린 글·사진은 같은 장에 모인다.
 */
class TradeReportService
{
    /** 이 시각(현장 기준) 이후에 미제출이면 늦은 것으로 본다. 기본 17시. */
    private const DUE_HOUR_KEY = 'ops.trade_report_due_hour';

    /**
     * 오늘 이 현장에서 보고가 있어야 하는 공종 — 출근 기록이 정본.
     *
     * @return array<int, string>
     */
    public function expectedTrades(int $siteId, string $workDate): array
    {
        $employeeIds = AttendanceLog::query()
            ->where('site_id', $siteId)
            ->where('attendance_date', $workDate)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->distinct()
            ->pluck('employee_id');

        if ($employeeIds->isEmpty()) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->whereNotNull('role')
            ->where('role', '!=', '')
            // 원청 소속은 우리 공종이 아니다 — 그들의 보고를 우리가 낼 수 없다.
            ->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', Employee::TYPE_CLIENT))
            ->distinct()
            ->orderBy('role')
            ->pluck('role')
            ->map(fn ($r): string => trim((string) $r))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 소장이 보는 현황판 — 공종별로 냈는가, 무엇이 들어왔는가.
     *
     * @return array<string, mixed>
     */
    public function board(int $siteId, ?string $workDate = null): array
    {
        $site = Site::query()->find($siteId);
        $tz = $site?->timezone ?: config('app.timezone');
        $workDate ??= Carbon::now($tz)->toDateString();

        $expected = $this->expectedTrades($siteId, $workDate);

        $reports = DailyTradeReport::query()
            ->where('site_id', $siteId)
            ->where('work_date', $workDate)
            ->withCount('batches')
            ->with('submittedBy.employee:id,name')
            ->get()
            ->keyBy('trade');

        // 기대 목록에 없는데 보고가 올라온 공종도 보여 준다 — 출근을 안 찍고 일한
        // 경우다. 숨기면 그 보고가 사라진 것처럼 보인다.
        $trades = collect($expected)->concat($reports->keys())->unique()->sort()->values();

        $rows = $trades->map(function (string $trade) use ($reports, $siteId, $workDate): array {
            $report = $reports->get($trade);
            $photos = $report ? $this->photoCount($report) : 0;

            return [
                'trade' => $trade,
                'status' => $report?->status ?? DailyTradeReport::STATUS_OPEN,
                'submitted' => (bool) $report?->isSubmitted(),
                'entries' => (int) ($report->batches_count ?? 0),
                'photos' => $photos,
                // 현장에서는 사람을 직원 이름으로 안다 — 계정 이름은 회사 메일 표기일 수 있다.
                'submittedBy' => $report?->submittedBy?->employee?->name ?: $report?->submittedBy?->name,
                'submittedAt' => $report?->submitted_at?->timezone(
                    Site::query()->whereKey($siteId)->value('timezone') ?: config('app.timezone'),
                )?->format('H:i'),
                'headcount' => $this->headcount($siteId, $workDate, $trade),
            ];
        })->all();

        $submitted = count(array_filter($rows, fn (array $r): bool => $r['submitted']));

        return [
            'success' => true,
            'date' => $workDate,
            'siteId' => $siteId,
            'rows' => $rows,
            'total' => count($rows),
            'submitted' => $submitted,
            'missing' => count($rows) - $submitted,
        ];
    }

    /**
     * 이 사람의 오늘 보고 — 없으면 만든다.
     *
     * 공종이 없는 사람(사무·관리 등)에게는 보고 장을 만들지 않는다. 만들면
     * 현황판에 낼 수 없는 줄이 하나 생긴다.
     */
    public function forUser(User $user, ?string $workDate = null): ?DailyTradeReport
    {
        $employee = $user->employee;
        $siteId = $employee?->site_id;
        $trade = trim((string) ($employee->role ?? ''));

        if (! $employee || ! $siteId || $trade === '') {
            return null;
        }

        $tz = $employee->site?->timezone ?: config('app.timezone');
        $workDate ??= Carbon::now($tz)->toDateString();

        return DailyTradeReport::query()->firstOrCreate(
            ['site_id' => $siteId, 'work_date' => $workDate, 'trade' => $trade],
            ['status' => DailyTradeReport::STATUS_OPEN],
        );
    }

    /**
     * 올라온 기록을 그날 그 공종의 보고에 묶는다.
     *
     * 상황실에 글을 올리는 순간 자동으로 불린다 — 반장에게 "어느 보고에 넣을까요"
     * 를 묻지 않는다. 그 답은 이미 알고 있다(그 사람의 공종, 오늘 날짜).
     */
    public function attach(OpsIntakeBatch $batch, ?User $user = null): void
    {
        $user ??= $batch->createdBy;
        if (! $user instanceof User) {
            return;
        }

        $site = Site::query()->find($batch->site_id);
        $tz = $site?->timezone ?: config('app.timezone');
        $workDate = ($batch->created_at ?: Carbon::now())->copy()->timezone($tz)->toDateString();

        $report = $this->forUser($user, $workDate);
        if (! $report || $report->site_id !== $batch->site_id) {
            return;
        }

        $batch->forceFill(['daily_trade_report_id' => $report->id])->save();
    }

    /**
     * 「오늘 보고 제출」.
     *
     * @return array<string, mixed>
     */
    public function submit(User $user, ?string $workDate = null): array
    {
        $report = $this->forUser($user, $workDate);
        if (! $report) {
            return ['success' => false, 'error' => '담당 공정이 지정되어 있지 않습니다. 관리자에게 공정 지정을 요청해 주세요.'];
        }

        if ($report->isSubmitted()) {
            return ['success' => true, 'already' => true, 'message' => '이미 제출된 보고입니다.'];
        }

        // 빈 보고는 받지 않는다. 아무것도 안 올리고 제출만 누르면 현황판은 초록인데
        // 종합보고서에는 그 공종이 비어 있다 — 그게 제일 나쁜 상태다.
        if ($report->batches()->count() === 0) {
            return ['success' => false, 'error' => '올린 기록이 없습니다. 오늘 한 일을 먼저 올려 주세요(사진만도 됩니다).'];
        }

        $report->forceFill([
            'status' => DailyTradeReport::STATUS_SUBMITTED,
            'submitted_by_id' => $user->id,
            'submitted_at' => now(),
            'reopen_reason' => null,
        ])->save();

        return [
            'success' => true,
            'message' => $report->trade.' 오늘 보고를 제출했습니다.',
            'trade' => $report->trade,
        ];
    }

    /**
     * 소장이 되돌린다 — 빠진 것이 있을 때.
     *
     * @return array<string, mixed>
     */
    public function reopen(int $reportId, string $reason, User $actor): array
    {
        $report = DailyTradeReport::query()->find($reportId);
        if (! $report) {
            return ['success' => false, 'error' => '해당 보고가 없습니다.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'error' => '되돌리는 까닭을 적어 주세요 — 그래야 반장이 무엇을 더 올릴지 압니다.'];
        }

        $report->forceFill([
            'status' => DailyTradeReport::STATUS_OPEN,
            'submitted_at' => null,
            'reopen_reason' => mb_substr($reason, 0, 250),
        ])->save();

        return ['success' => true, 'message' => $report->trade.' 보고를 되돌렸습니다.'];
    }

    /** 마감 시각(현장 기준) — 이 시각 이후의 미제출이 "늦은" 것이다. */
    public function dueHour(): int
    {
        return \App\Support\Org::int(self::DUE_HOUR_KEY, 17);
    }

    /** 그 공종으로 오늘 출근한 인원 수 — 현황판에서 "몇 명이 일했는데 보고가 없다" 를 보여준다. */
    private function headcount(int $siteId, string $workDate, string $trade): int
    {
        $employeeIds = AttendanceLog::query()
            ->where('site_id', $siteId)
            ->where('attendance_date', $workDate)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->distinct()
            ->pluck('employee_id');

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->where('role', $trade)
            ->count();
    }

    private function photoCount(DailyTradeReport $report): int
    {
        return (int) $report->batches()->sum('image_count');
    }
}
