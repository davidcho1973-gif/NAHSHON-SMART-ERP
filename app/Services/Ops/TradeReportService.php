<?php

namespace App\Services\Ops;

use App\Jobs\ReflectTradeReportJob;
use App\Models\AttendanceLog;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\Site;
use App\Models\User;
use App\Support\Org;
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

        // 현장을 고르지 않았으면 그렇게 말한다. 여기서 빈 목록을 돌려주면 화면은
        // "오늘 출근한 공종이 없습니다" 라고 하는데, 사실은 현장을 안 골랐을 뿐이다.
        if (! $site) {
            return [
                'success' => true,
                'noSite' => true,
                'date' => $workDate,
                'siteId' => null,
                'rows' => [],
                'total' => 0,
                'submitted' => 0,
                'missing' => 0,
            ];
        }

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
        //
        // 다만 <b>아무것도 담기지 않은 빈 장</b>은 세지 않는다. 그 장은 아무 사실도
        // 나타내지 않는데, 세어 버리면 현황판과 마감보고서에 «미제출» 로 찍혀
        // 일하지 않은 공종이 보고를 안 낸 것처럼 원청에 나간다.
        $real = $reports->filter(
            fn (DailyTradeReport $r): bool => $r->isSubmitted() || (int) ($r->batches_count ?? 0) > 0,
        );

        $trades = collect($expected)->concat($real->keys())->unique()->sort()->values();

        $rows = $trades->map(function (string $trade) use ($reports, $siteId, $workDate, $tz): array {
            $report = $reports->get($trade);
            $photos = $report ? $this->photoCount($report) : 0;

            return [
                'trade' => $trade,
                // 되돌리기가 이 번호로 걸린다 — 없으면 화면에서 되돌릴 방법이 없다.
                'reportId' => $report?->id,
                'status' => $report?->status ?? DailyTradeReport::STATUS_OPEN,
                'submitted' => (bool) $report?->isSubmitted(),
                'entries' => (int) ($report->batches_count ?? 0),
                'photos' => $photos,
                // 현장에서는 사람을 직원 이름으로 안다 — 계정 이름은 회사 메일 표기일 수 있다.
                'submittedBy' => $report?->submittedBy?->employee?->name ?: $report?->submittedBy?->name,
                'submittedAt' => $report?->submitted_at?->timezone($tz)?->format('H:i'),
                'headcount' => $this->headcount($siteId, $workDate, $trade),
                // 제출이 곧 반영이다 — 몇 건이 ERP 로 넘어갔고 몇 건이 사람을 기다리는가.
                'applied' => (int) ($report->applied_count ?? 0),
                'held' => (int) ($report->held_count ?? 0),
                'note' => $report?->reflection_note,
                // 그 공종이 오늘 무엇을 보고했는가 — 마감보고서의 공종별 줄이 이걸 쓴다.
                'highlights' => $report ? $this->highlights($report) : [],
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
            // 이름을 함께 낸다. 마감보고서에 "덕트 미제출" 이 이름으로 찍혀야
            // 빠진 공종이 있는 채로 원청에 나가는 일이 없다.
            'missingTrades' => array_values(array_map(
                fn (array $r): string => $r['trade'],
                array_filter($rows, fn (array $r): bool => ! $r['submitted']),
            )),
            'held' => array_sum(array_column($rows, 'held')),
            'applied' => array_sum(array_column($rows, 'applied')),
        ];
    }

    /**
     * 이 사람의 오늘 보고 — <b>있으면</b> 돌려준다. 만들지는 않는다.
     *
     * 조회가 행을 만들면 안 된다. 쉬는 날 반장이 상황실 화면을 열어 보기만 해도
     * 보고 장이 생기고, 그 빈 장이 현황판에 «미제출» 로 뜨고, 마감보고서의
     * «미제출 공종» 이 되어 원청에 나간다 — 아무도 일하지 않은 공종이 보고를
     * 안 냈다고 적히는 것이다.
     */
    public function forUser(User $user, ?string $workDate = null): ?DailyTradeReport
    {
        [$siteId, $trade, $workDate] = $this->slotFor($user, $workDate);
        if ($siteId === null) {
            return null;
        }

        return DailyTradeReport::query()
            ->where('site_id', $siteId)
            ->where('work_date', $workDate)
            ->where('trade', $trade)
            ->first();
    }

    /**
     * 이 사람의 오늘 보고 — 없으면 만든다.
     *
     * 무언가를 <b>올리거나 제출할 때만</b> 부른다. 그때는 그 사람이 오늘 일했다는
     * 사실이 이미 증명된 셈이라 장을 만들어도 거짓이 되지 않는다.
     *
     * 공종이 없는 사람(사무·관리 등)에게는 만들지 않는다 — 현황판에 낼 수 없는
     * 줄이 하나 생긴다.
     */
    public function forUserOrCreate(User $user, ?string $workDate = null): ?DailyTradeReport
    {
        [$siteId, $trade, $workDate] = $this->slotFor($user, $workDate);
        if ($siteId === null) {
            return null;
        }

        return DailyTradeReport::query()->firstOrCreate(
            ['site_id' => $siteId, 'work_date' => $workDate, 'trade' => $trade],
            ['status' => DailyTradeReport::STATUS_OPEN],
        );
    }

    /**
     * 이 사람의 오늘 보고가 놓일 자리 — 현장·공종·날짜.
     *
     * @return array{0: ?int, 1: string, 2: string}
     */
    private function slotFor(User $user, ?string $workDate): array
    {
        $employee = $user->employee;
        $siteId = $employee?->site_id;
        $trade = trim((string) ($employee->role ?? ''));

        if (! $employee || ! $siteId || $trade === '') {
            return [null, '', (string) $workDate];
        }

        $tz = $employee->site?->timezone ?: config('app.timezone');

        return [$siteId, $trade, $workDate ?? Carbon::now($tz)->toDateString()];
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

        $report = $this->forUserOrCreate($user, $workDate);
        if (! $report) {
            return;
        }

        // 현장 없이 올라온 기록은 <b>버리지 않고</b> 그 사람의 현장으로 채운다.
        //
        // 모바일 상황실은 오랫동안 siteId 를 'ALL' 로 보냈고, 그래서 배치가 site_id=null
        // 로 저장됐다. 여기서 엄격 비교(int !== null)로 걸러 내는 바람에 폰에서 올린
        // 기록은 <b>단 한 건도</b> 보고에 묶이지 않았다 — 「올린 기록 0건」, 제출은 늘
        // "올린 기록이 없습니다". 화면 쪽도 고쳤지만, 여기서도 받아 줘야 예전 기록과
        // 다른 경로로 들어온 기록이 같은 이유로 사라지지 않는다.
        if ($batch->site_id === null) {
            $batch->forceFill(['site_id' => $report->site_id])->save();
        } elseif ($report->site_id !== $batch->site_id) {
            return;   // 다른 현장의 기록 — 남의 현장 보고에 섞으면 안 된다.
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
        $report = $this->forUserOrCreate($user, $workDate);
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
            // 제출 순간에는 아직 안 돌았다. 지난 제출의 결과가 남아 있으면 반장이
            // 그것을 이번 결과로 읽는다 — 숫자까지 전부 비워야 정직하다.
            'reflected_at' => null,
            'reflection_note' => null,
            'applied_count' => 0,
            'held_count' => 0,
        ])->save();

        // 제출이 곧 반영이다. 다만 응답을 보낸 뒤에 돌린다 — 공정표 한 줄을 고치면
        // CPM 이 후속 공정을 다시 계산하고, 그게 느린 날 반장 화면에는 «제출 실패» 가
        // 뜬다(실제로는 제출도 반영도 됐는데).
        //
        // 제출 시각을 함께 실어 보낸다. 잡이 도는 사이에 소장이 되돌리고 반장이 다시
        // 제출하면 잡이 둘 겹치는데, 그때 «내가 실려온 그 제출이 맞나» 를 잡 스스로
        // 확인할 수 있어야 한다.
        ReflectTradeReportJob::dispatch($report->id, $report->submitted_at?->timestamp)->afterResponse();

        return [
            'success' => true,
            'message' => $report->trade.' 오늘 보고를 제출했습니다. 올리신 내용을 ERP 에 반영하고 있습니다.',
            'trade' => $report->trade,
            'reportId' => $report->id,
            'reflecting' => true,
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
            // 되돌린 보고가 지난 제출의 반영 숫자를 계속 달고 있으면, 현황판에
            // «미제출인데 반영 3건» 이라는 읽을 수 없는 줄이 남는다.
            'applied_count' => 0,
            'held_count' => 0,
            'reflected_at' => null,
            'reflection_note' => null,
        ])->save();

        // 자동 반영이 «되물음» 으로 올려 둔 것을 원위치시킨다.
        //
        // 되돌리기는 "다시 해 보자" 는 뜻이다. 이걸 안 풀면 다음 제출에서 그 항목은
        // 후보에조차 들어가지 않고(되물음은 자동 반영 대상이 아니다), 반장 화면에는
        // 「확인 필요 1건」이 영원히 같은 숫자로 남는다 — 반장이 손댈 방법이 없는 채로.
        app(TradeReportReflector::class)->releaseHolds($report);

        return ['success' => true, 'message' => $report->trade.' 보고를 되돌렸습니다.'];
    }

    /** 마감 시각(현장 기준) — 이 시각 이후의 미제출이 "늦은" 것이다. */
    public function dueHour(): int
    {
        return Org::int(self::DUE_HOUR_KEY, 17);
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

    /**
     * 그 공종이 오늘 보고한 내용 — 판독된 한 줄 요약들.
     *
     * 원문 전체가 아니라 요약을 쓴다. 마감보고서는 원청이 보는 문서라 카톡 원문이
     * 그대로 실리면 안 되고("아 그거 내일 하죠 ㅋㅋ"), 잡담은 애초에 걸러져 있다.
     *
     * @return array<int, string>
     */
    private function highlights(DailyTradeReport $report, int $limit = 6): array
    {
        $batchIds = $report->batches()->pluck('id');
        if ($batchIds->isEmpty()) {
            return [];
        }

        return OpsIntakeItem::query()
            ->whereIn('ops_intake_batch_id', $batchIds)
            ->where('category', '!=', 'noise')
            ->whereNotNull('summary')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('summary')
            ->map(fn ($s): string => trim((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
