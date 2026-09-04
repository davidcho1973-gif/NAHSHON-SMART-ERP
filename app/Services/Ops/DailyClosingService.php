<?php

namespace App\Services\Ops;

use App\Jobs\WriteDailyClosingReportJob;
use App\Models\DailyClosingReport;
use App\Models\DailyCrewReport;
use App\Models\DailyTradeReport;
use App\Models\Equipment;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use App\Models\SafetyPermit;
use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\WbsItem;
use App\Models\WbsPhoto;
use App\Services\Attendance\DailyHeadcountService;
use App\Services\Ocr\OcrEngine;
use App\Services\Wbs\WbsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 일일 마감 보고서 — 마감 버튼 한 번으로 그날 상황실에 올라온 모든 것을 정리한다.
 *
 * 설계 원칙: **숫자는 DB 에서, 문장은 AI 에서, 그리고 사람이 쓴 것은 사람 것 그대로.**
 *
 * 인원·진도·지출 같은 수치를 AI 에게 세게 하면 보고서의 숫자가 틀릴 수 있고, 숫자가 한 번
 * 틀리면 보고서 전체를 못 믿는다. 그래서 집계는 코드가 정확히 뽑고, AI 는 그 숫자를 근거로
 * 총평·이슈·내일 계획만 쓴다.
 *
 * 여기에 하나가 더 있다 — **현장소장이 현장앱에서 직접 쓴 보고**(`metrics.field`).
 * 예전에는 그게 `field_daily_reports` 라는 별도 표에 있어서 이 마감이 못 봤고, 그래서
 * AI 가 "오늘 한 일 / 내일 할 일" 을 처음부터 다시 썼다. 같은 것을 두 사람이 두 번 쓰고
 * 어긋나면 가릴 방법이 없는 상태였다. 2026-08-18 에 표를 하나로 합쳐서, 이제 사람이 쓴
 * 문장은 그대로 살리고 AI 는 거기에 집계가 드러낸 것만 덧붙인다.
 */
class DailyClosingService
{
    public function __construct(
        private readonly OcrEngine $engine,
        private readonly DailyHeadcountService $headcount,
        private readonly OpsActionService $actions,
    ) {}

    /**
     * 마감을 시작한다 — 보고서 레코드를 만들고 즉시 돌아온다(작성은 응답 후).
     *
     * @return array<string, mixed>
     */
    public function start(?int $siteId, ?string $date = null, ?int $userId = null): array
    {
        $site = $siteId ? Site::find($siteId) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $reportDate = $date ?: Carbon::now($tz)->toDateString();

        $report = DailyClosingReport::updateOrCreate(
            ['site_id' => $siteId, 'report_date' => $reportDate],
            ['status' => 'writing', 'error' => null, 'closed_by_id' => $userId, 'closed_at' => now()],
        );

        WriteDailyClosingReportJob::dispatch($report->id)->afterResponse();

        return ['success' => true, 'status' => 'writing', 'reportId' => $report->id, 'date' => $reportDate];
    }

    /** 실제 작성(백그라운드). 집계 → AI 서술 → 저장. */
    public function write(int $reportId): void
    {
        $report = DailyClosingReport::find($reportId);
        if (! $report) {
            return;
        }

        config(['services.gemini.timeout' => max(300, (int) config('services.gemini.timeout'))]);

        try {
            $metrics = $this->metrics($report->site_id, $report->report_date->toDateString());
            $narrative = $this->narrate($report, $metrics);

            $report->update([
                'metrics' => $metrics,
                'narrative' => $narrative,
                'status' => 'done',
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $report->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    /**
     * 그날의 확정 집계 — 전부 DB 에서 뽑는다.
     *
     * @return array<string, mixed>
     */
    public function metrics(?int $siteId, string $date): array
    {
        $site = $siteId ? Site::find($siteId) : null;

        // 현장이 직접 쓴 것. 같은 줄에 있으므로 다른 표를 찾아갈 필요가 없다.
        $report = DailyClosingReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->whereDate('report_date', $date)
            ->first();

        // ── 인원: 상황실 보고 vs 게이트 QR 실적. 이 차이가 이 보고서의 핵심이다.
        $reports = OpsLaborReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('work_date', $date)->with('company')->get();

        $reportedByCompany = $reports->map(fn (OpsLaborReport $r): array => [
            'company' => $r->label(),
            'trade' => $r->trade,
            'headcount' => $r->headcount,
        ])->sortByDesc('headcount')->values()->all();

        $actual = $this->headcount->today($siteId, $date);
        $reportedTotal = (int) $reports->sum('headcount');

        // 상황실 인원 보고가 없는 날이면 <b>아침 작업계획서</b>의 투입 인원을 쓴다.
        // 계획서에 "나손 전기 6명" 이라고 적고 저녁 보고서에 "0명" 이 찍히면
        // 원청은 그 보고서를 못 믿는다. 같은 것을 두 번 입력시키지 않는 것이
        // 이 시스템의 원칙이므로, 아침에 쓴 것이 저녁에 자동으로 흘러가야 한다.
        if ($reportedByCompany === [] && $report && ($report->plan['crews'] ?? []) !== []) {
            foreach ($report->plan['crews'] as $c) {
                $head = (int) ($c['headcount'] ?? 0);
                if ($head <= 0) {
                    continue;
                }
                $reportedByCompany[] = [
                    'company' => (string) ($c['company'] ?? ''),
                    'trade' => (string) ($c['trade'] ?? ''),
                    'headcount' => $head,
                    // 어디서 온 숫자인지 밝힌다 — 출처가 없는 숫자는 나중에 설명하지 못한다.
                    'source' => '작업계획서',
                ];
                $reportedTotal += $head;
            }
        }
        $actualTotal = (int) ($actual['direct']['count'] + $actual['indirect']['count'] + $actual['other']['count']);

        $crew = $this->crewSummary($siteId, $date);

        // ── 상황실 활동
        //
        // 날짜 경계를 현장 시간대로 잡는다. created_at 은 앱 시간대의 벽시계로 저장되고
        // $date 는 현장 로컬 날짜라, whereDate 로 그냥 비교하면 시차가 있는 현장에서
        // 하루가 통째로 어긋난다 — 그날 올라온 보고가 마감 집계에서 사라지고,
        // 진척 목록의 «누가 말했는가» 도 함께 비어 버린다.
        [$from, $to] = $this->localDayWindow($site, $date);

        $batches = OpsIntakeBatch::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->get();

        $items = OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereIn('ops_intake_batch_id', $batches->pluck('id'))
            ->get();

        $byCategory = $items->groupBy('category')->map->count();

        // 공종별 일일보고 — 마감의 뼈대. 각 반장이 낸 것이 여기 모이고, 안 낸 공종은
        // 안 낸 채로 드러난다. 이 블록이 없으면 마감보고서는 "누가 무엇을 말했는지"
        // 없이 숫자만 남고, 빠진 공종이 있어도 알 방법이 없다.
        $trades = $this->tradeReportSummary($siteId, $date, $batches, $items);

        return [
            'date' => $date,
            'site' => ['id' => $siteId, 'name' => $site?->name, 'code' => $site?->code],
            'labor' => [
                'reported' => $reportedTotal,
                'actualQr' => $actualTotal,
                'gap' => $reportedTotal - $actualTotal,
                'byCompany' => $reportedByCompany,
                'directHours' => $actual['direct']['workedHours'],
                'directAvgHours' => $actual['direct']['avgHours'],
                'openClockOut' => $actual['direct']['open'] + $actual['indirect']['open'],
                // 모바일 팀 마감이 남긴 것 — QR 에 안 잡히는 외부 인원과 수기 보정.
                'crew' => $crew,
                // 그날 최종 인원. QR 실적에 팀 마감의 외부인원·보정을 얹은 값이고,
                // 이 보고서가 그 정본이다. 예전에는 이 숫자가 팀별 daily_crew_reports 에
                // 흩어져 있어서 현장 단위 최종 인원을 아무도 갖고 있지 않았다.
                'final' => max(0, $actualTotal + $crew['external'] + $crew['adjustment']),
            ],
            'ops' => [
                'batches' => $batches->count(),
                'photos' => (int) $batches->sum('image_count'),
                'evidenceFiled' => (int) $batches->sum('evidence_filed'),
                'parsed' => $items->count(),
                'applied' => $items->where('status', 'applied')->count(),
                'pending' => $items->whereIn('status', ['pending', 'needs_input'])->count(),
                'byCategory' => $byCategory->all(),
            ],
            'progress' => $items->where('category', 'progress')->map(fn (OpsIntakeItem $i): array => [
                'target' => $i->target_name ?: $i->target_code,
                'summary' => $i->summary,
                'proposed' => $i->proposed,
                'status' => $i->status,
                // 누가 말한 숫자인지 붙인다. 출처 없는 진척률은 나중에 아무도 설명하지 못한다.
                'trade' => $trades['byBatch'][$i->ops_intake_batch_id]['trade'] ?? null,
                'reportedBy' => $trades['byBatch'][$i->ops_intake_batch_id]['by'] ?? null,
            ])->values()->all(),
            'procurement' => $items->where('category', 'procurement')->map(fn (OpsIntakeItem $i): array => [
                'target' => $i->target_name ?: $i->target_code,
                'summary' => $i->summary,
                'status' => $i->status,
            ])->values()->all(),
            'issues' => $items->where('category', 'issue')->map(fn (OpsIntakeItem $i): array => [
                'summary' => $i->summary,
                'status' => $i->status,
            ])->values()->all(),
            'expenses' => $items->where('category', 'expense')->map(fn (OpsIntakeItem $i): array => [
                'summary' => $i->summary,
                'amount' => (float) (($i->proposed['amount'] ?? 0)),
                'status' => $i->status,
            ])->values()->all(),
            // 공정·자재·인원 어디에도 안 들어가는 것들 — 원청 지시·승인·의사결정·준비물.
            // 이게 곧 "오늘 한 일 / 내일 할 일" 이다.
            'actions' => $this->actionSummary($siteId, $date),

            // ── 공종별 일일보고. 각 반장이 자기 몫으로 낸 것과, 안 낸 공종.
            'tradeReports' => $trades['board'],

            // 현장 공정률 — 공정표에서 계산한 값(진척률의 정본 산식). 현장이 손으로 적은
            // field.progressRate 와 <b>나란히</b> 둔다. 둘을 하나로 합치지 않는 이유는
            // 어긋날 때가 곧 관리 포인트이기 때문이다(보고 70% / 공정표 55%).
            'schedule' => $this->scheduleProgress($siteId),

            // ── 원청 제출용으로 새로 붙인 세 블록. 예전 보고서에는 없다.
            // 마감 화면만 볼 때는 없어도 그만이었지만, 원청에 내는 일보에는
            // 장비·안전·사진이 빠지면 반려된다.
            'equipment' => $this->equipmentSummary($siteId),
            'safety' => $this->safetySummary($siteId, $date),
            'photos' => $this->photoSummary($siteId, $date),

            // 현장소장이 현장앱에서 직접 쓴 것. 날씨·오늘 한 일·내일 할 일·진도율·TBM.
            // 사람이 본 것이라 이 보고서에서 가장 1차 사실에 가깝고, AI 서술은 이걸
            // 다시 쓰는 게 아니라 근거로 삼는다.
            'field' => $report && $report->hasFieldReport() ? $report->fieldReport() : null,
        ];
    }

    /**
     * 공종별 일일보고를 마감으로 끌어온다.
     *
     * 반장이 낸 보고는 하루 종일 상황실로 들어오지만, 그것이 "누가 낸 무엇" 인지는
     * 공종별 보고(daily_trade_reports)에만 있다. 마감이 그걸 안 읽으면 보고서에는
     * 숫자만 남고 출처가 사라진다 — 원청이 "이 60% 는 누가 본 겁니까" 라고 물으면
     * 답할 사람이 없다.
     *
     * 함께 돌려주는 byBatch 는 판독 항목 → 공종·보고자 되짚기용 색인이다. 항목마다
     * 관계를 타고 올라가면 쿼리가 항목 수만큼 늘어난다.
     *
     * @param  Collection<int, OpsIntakeBatch>  $batches
     * @param  Collection<int, OpsIntakeItem>  $items
     * @return array{board: array<string, mixed>, byBatch: array<int, array{trade: string, by: ?string}>}
     */
    private function tradeReportSummary(?int $siteId, string $date, $batches, $items): array
    {
        $board = $this->tradeBoard($siteId, $date);

        if ($board['rows'] === [] && $board['total'] === 0) {
            return ['board' => $board, 'byBatch' => []];
        }

        // 배치 → 공종·보고자. 그날 그 현장의 보고만 대상이라 색인이 작다.
        $reports = DailyTradeReport::query()
            ->where('site_id', $siteId)
            ->where('work_date', $date)
            ->with('submittedBy.employee:id,name')
            ->get()
            ->keyBy('id');

        $byBatch = [];
        foreach ($batches as $batch) {
            $report = $reports->get($batch->daily_trade_report_id);
            if (! $report) {
                continue;
            }
            $byBatch[$batch->id] = [
                'trade' => (string) $report->trade,
                'by' => $report->submittedBy?->employee?->name ?: $report->submittedBy?->name,
            ];
        }

        return ['board' => $board, 'byBatch' => $byBatch];
    }

    /**
     * 그날 그 현장의 공종별 보고 현황 — 마감이 싣는 모양으로.
     *
     * @return array<string, mixed>
     */
    private function tradeBoard(?int $siteId, string $date): array
    {
        $empty = [
            'total' => 0, 'submitted' => 0, 'missing' => 0,
            'missingTrades' => [], 'applied' => 0, 'held' => 0, 'rows' => [],
        ];

        // 현장을 고르지 않은 마감(전 현장 합산)에는 공종별 보고가 성립하지 않는다 —
        // 공종은 현장 안에서만 뜻이 있다.
        if (! $siteId) {
            return $empty;
        }

        try {
            $board = app(TradeReportService::class)->board($siteId, $date);
        } catch (\Throwable $e) {
            report($e); // 공종 블록을 못 만들어도 마감 자체는 나가야 한다.

            return $empty;
        }

        if ($board['noSite'] ?? false) {
            return $empty;
        }

        return [
            'total' => (int) $board['total'],
            'submitted' => (int) $board['submitted'],
            'missing' => (int) $board['missing'],
            'missingTrades' => $board['missingTrades'] ?? [],
            'applied' => (int) ($board['applied'] ?? 0),
            'held' => (int) ($board['held'] ?? 0),
            'rows' => array_map(fn (array $r): array => [
                'trade' => $r['trade'],
                'kind' => $r['kind'] ?? 'trade',
                'submitted' => $r['submitted'],
                'submittedBy' => $r['submittedBy'],
                'submittedAt' => $r['submittedAt'],
                'headcount' => $r['headcount'],
                'entries' => $r['entries'],
                'photos' => $r['photos'],
                'applied' => $r['applied'],
                'held' => $r['held'],
                // 반영 결과 한 줄. 여기서 빠뜨리면 「사진 판독 중」 같은 유일한
                // 경고가 마감에도 AI 프롬프트에도 도달하지 않는다 — 제출은 됐는데
                // 내용이 빈 공종이 아무 설명 없이 원청 보고서에 실린다.
                'note' => $r['note'] ?? null,
                'highlights' => $r['highlights'],
            ], $board['rows']),
        ];
    }

    /**
     * 그 현장의 하루가 시작하고 끝나는 순간 — 앱 시간대 기준으로.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function localDayWindow(?Site $site, string $date): array
    {
        $siteTz = $site?->timezone ?: config('app.timezone');
        $appTz = config('app.timezone');

        $from = Carbon::parse($date, $siteTz)->startOfDay();

        return [
            $from->copy()->setTimezone($appTz),
            $from->copy()->addDay()->setTimezone($appTz),
        ];
    }

    /**
     * 공정표에서 계산한 현장 공정률.
     *
     * 산식은 하나뿐이다 — WbsService::weightedProgress(공수 → 공기 → 균등). 마감이
     * 자기 식으로 다시 계산하면 같은 현장에 진척률이 두 개 생기고, 두 개가 되는 순간
     * 둘 다 못 믿는다.
     *
     * @return array<string, mixed>
     */
    private function scheduleProgress(?int $siteId): array
    {
        if (! $siteId) {
            return ['rate' => null, 'tasks' => 0, 'done' => 0];
        }

        try {
            $subtasks = WbsItem::query()
                // 안전 작업카드의 실측 물량이 진척률의 일부다. 미리 읽지 않으면
                // WbsItem::effectiveProgress 가 <b>지연 로딩이 아니라 빈 컬렉션</b>으로
                // 폴백해 현장 실측이 통째로 빠진다 — 정본 경로(WbsService::itemsFor)와
                // 다른 숫자가 나오고, 그 차이를 원청 보고서가 «확인 필요» 로 적는다.
                ->with('safetyWorkItems.signatures')
                // 현장 스코프도 정본과 같아야 한다. 임포트에서 현장이 안 잡힌 행은
                // site_id 가 null 로 들어오는데, 정본은 그것을 일부러 포함한다.
                ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $siteId))
                ->where('level', WbsItem::LEVEL_SUBTASK)
                ->get();

            if ($subtasks->isEmpty()) {
                return ['rate' => null, 'tasks' => 0, 'done' => 0];
            }

            return [
                'rate' => app(WbsService::class)->weightedProgress($subtasks),
                'tasks' => $subtasks->count(),
                'done' => $subtasks->where('status', WbsItem::STATUS_DONE)->count(),
                // 어디서 온 숫자인지 밝힌다 — 출처가 없는 숫자는 나중에 설명하지 못한다.
                'source' => '공정표(가중 진척률)',
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['rate' => null, 'tasks' => 0, 'done' => 0];
        }
    }

    /**
     * 그날 가동한 장비 — <b>'사용중' 인 것만</b> 표로 낸다.
     *
     * 장비에는 날짜별 가동 기록이 없다(대장에 현장 배치와 상태만 있다). 그래서
     * 처음에는 현장에 있는 장비를 전부 실었는데, 703K 처럼 143대가 있는 현장에서는
     * 일보에 40줄이 붙어 나갔다. 원청이 묻는 것은 "오늘 무엇이 돌았나" 이지
     * "창고에 무엇이 있나" 가 아니다 — 대기 중인 장비까지 «가동 장비» 로 적어
     * 보내는 것은 사실과 다르다.
     *
     * 그래서 표에는 사용중인 것만 넣고, 현장 보유 대수는 숫자로만 함께 낸다.
     *
     * @return array<string, mixed>
     */
    private function equipmentSummary(?int $siteId): array
    {
        if (! $siteId) {
            return ['count' => 0, 'onSite' => 0, 'rows' => []];
        }

        $all = Equipment::query()
            ->where('site_id', $siteId)
            ->orderBy('equipment_type')
            ->get(['equipment_code', 'equipment_type', 'model', 'status']);

        $inUse = $all->where('status', '사용중');

        return [
            'count' => $inUse->count(),
            'onSite' => $all->where('status', '<>', '정비중')->count(),
            'maintenance' => $all->where('status', '정비중')->count(),
            'rows' => $inUse->take(30)->map(fn (Equipment $e): array => [
                'name' => trim((string) $e->equipment_type.' '.(string) $e->model),
                'code' => (string) $e->equipment_code,
                'status' => (string) $e->status,
            ])->values()->all(),
        ];
    }

    /**
     * 그날의 안전 집계 — TBM 이 몇 건 중 몇 건 끝났는지, 허가서와 지적사항은 몇 건인지.
     *
     * 현장이 체크한 `safety_checks` 원문만으로는 원청이 못 믿는다. "오늘 작업카드
     * 7건 중 7건 TBM 완료" 같은 수치가 있어야 안전 보고가 성립한다.
     *
     * @return array<string, mixed>
     */
    private function safetySummary(?int $siteId, string $date): array
    {
        $cards = SafetyWorkItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('work_date', $date)
            ->get(['id', 'tbm_status']);

        $permits = SafetyPermit::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->whereNotIn('status', ['취소', '만료'])
            ->count();

        $issues = $cards->isEmpty() ? 0 : SafetyWorkIssue::query()
            ->whereIn('safety_work_item_id', $cards->pluck('id'))
            ->where('status', '<>', '완료')
            ->count();

        return [
            'cards' => $cards->count(),
            'tbmDone' => $cards->where('tbm_status', '완료')->count(),
            'permits' => $permits,
            'issues' => $issues,
        ];
    }

    /**
     * 그날 올라온 작업 사진 — 보고서에 첨부할 원본이 몇 장 있는지.
     *
     * 상황실 사진은 판독 후 지워지므로 여기서 세지 않는다. 남는 것은 공정별로
     * 쌓이는 `wbs_photos` 뿐이고, 원청에 보낼 사진도 그것이다.
     *
     * @return array<string, mixed>
     */
    private function photoSummary(?int $siteId, string $date): array
    {
        if (! $siteId) {
            return ['count' => 0, 'captions' => []];
        }

        $rows = WbsPhoto::query()
            ->where('site_id', $siteId)
            ->whereDate('photo_date', $date)
            ->orderBy('id')
            ->limit(30)
            ->get(['caption', 'wbs_code']);

        return [
            'count' => $rows->count(),
            'captions' => $rows->map(fn (WbsPhoto $p): string => (string) ($p->caption ?: $p->wbs_code))
                ->filter()->unique()->take(8)->values()->all(),
        ];
    }

    /**
     * 팀별 모바일 마감을 현장 마감으로 끌어온다.
     *
     * 마감이 두 군데였다. 모바일 출퇴근앱은 팀 단위로 daily_crew_reports 에,
     * 상황실은 현장 단위로 daily_closing_reports 에 남기면서 서로를 몰랐다.
     * 그래서 그날 최종 인원이 두 벌 남고 어느 쪽이 정본인지 알 수 없었다.
     *
     * 이제 현장 마감이 팀 마감을 읽어 정본을 만든다. 팀 마감은 "입력",
     * 현장 마감은 "확정" 이다. QR 에 안 잡히는 외부 인원과 수기 보정은
     * 현장에서만 알 수 있으니 팀 마감이 계속 필요하고, 다만 결론은 한 곳에 모인다.
     *
     * @return array{teams: array<int, array<string, mixed>>, external: int, adjustment: int, scanned: int, closedTeams: int}
     */
    private function crewSummary(?int $siteId, string $date): array
    {
        $rows = DailyCrewReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('work_date', $date)
            ->with('team:id,name')
            ->orderByDesc('final_headcount')
            ->get();

        return [
            'teams' => $rows->map(fn (DailyCrewReport $r): array => [
                'team' => $r->team?->name ?: '팀 미지정',
                'scanned' => (int) $r->scanned_headcount,
                'external' => (int) $r->external_headcount,
                'adjustment' => (int) $r->manual_adjustment,
                'final' => (int) $r->final_headcount,
                // 보정에는 사유가 붙어야 한다. 숫자만 바뀌고 이유가 없으면 나중에 아무도 설명 못 한다.
                'adjustmentReason' => $r->adjustment_reason,
                'workDescription' => $r->work_description,
                'closedAt' => $r->closed_at?->toIso8601String(),
            ])->values()->all(),
            'scanned' => (int) $rows->sum('scanned_headcount'),
            'external' => (int) $rows->sum('external_headcount'),
            'adjustment' => (int) $rows->sum('manual_adjustment'),
            'closedTeams' => $rows->where('status', 'closed')->count(),
        ];
    }

    /**
     * 액션 아이템 요약 — 오늘 처리한 것, 내일 할 것, 늦은 것, 막힌 것.
     *
     * @return array<string, mixed>
     */
    private function actionSummary(?int $siteId, string $date): array
    {
        $board = $this->actions->board($siteId, $date);

        $strip = fn (array $rows): array => array_map(fn (array $r): array => [
            'title' => $r['title'],
            'kind' => $r['kindLabel'],
            'requester' => $r['requester'],
            'assignee' => $r['assignee'],
            'dueOn' => $r['dueOn'],
            'isBlocker' => $r['isBlocker'],
        ], $rows);

        return [
            'doneToday' => $strip($board['doneToday']),
            'tomorrow' => $strip($board['tomorrow']),
            'overdue' => $strip($board['overdue']),
            'blockers' => $strip($board['blockers']),
            'undated' => $strip($board['undated']),
            'openTotal' => $board['openTotal'],
        ];
    }

    /**
     * AI 서술 — 위 집계를 근거로 총평·이슈·내일 계획을 쓴다.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function narrate(DailyClosingReport $report, array $metrics): array
    {
        $json = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장 설치현장의 공사부장입니다.
아래는 오늘 하루 현장에서 집계된 **확정 수치**입니다. 이 숫자를 근거로 원청에 제출할
**일일 마감 보고서의 서술 부분**을 작성하세요. JSON 만 반환합니다.

[오늘 집계]
{$json}

## 반드시 지킬 것
1. **숫자를 새로 만들지 마세요.** 위 집계에 있는 숫자만 인용하세요. 없는 값은 언급하지 마세요.
2. 인원의 `reported`(현장 보고)와 `actualQr`(게이트 QR 실적)의 차이(gap)가 0이 아니면
   **반드시 짚으세요.** 이건 출역 확인이 필요한 관리 사항입니다.
   - gap > 0 : 보고보다 QR 기록이 적음 → 미등록 인원이거나 QR 미스캔
   - gap < 0 : QR 기록이 보고보다 많음 → 보고 누락
3. 오늘 올라온 내용이 거의 없으면(batches 0~1) 억지로 길게 쓰지 말고 그렇다고 쓰세요.
3-0. `field` 는 **현장소장이 오늘 현장앱에서 직접 쓴 보고**입니다(있을 때만 들어옵니다).
   사람이 본 것이므로 이 보고서에서 가장 1차 사실에 가깝습니다.
   - `field.workToday` 가 있으면 그 내용이 **오늘 한 일의 정본**입니다. 바꿔 쓰지 말고
     done 배열 **맨 앞**에 그대로 살리고, 집계에서 추가로 드러난 것만 뒤에 덧붙이세요.
   - `field.workTomorrow` 가 있으면 tomorrow 의 맨 앞에 그대로 살리세요.
   - `field.progressRate` 는 현장이 보고한 진도율입니다. progressNote 에 반드시 쓰세요.
   - `field.tbmCompleted` 가 false 이거나 `field.safetyChecks` 에 false 가 있으면
     attention 에 넣으세요 — 안전점검 미완은 그날 안에 확인해야 합니다.
   - `field` 가 null 이면 현장 보고가 아직 없다는 뜻입니다. 그 사실을 attention 에 쓰세요.
3-2. `tradeReports` 는 **공종별 반장이 오늘 직접 낸 보고**입니다. 이 보고서의 뼈대입니다.
   - `tradeReports.rows[].highlights` 가 그 공종이 오늘 보고한 내용입니다. done(오늘 한 일)에
     **공종 이름을 붙여** 반영하세요(예: "배관 — 3층 천장 배관 12/20 완료").
   - `tradeReports.missingTrades` 가 비어 있지 않으면 attention 에 **반드시** 쓰세요.
     그 공종은 사람이 나와서 일했는데 보고가 없습니다. 이 보고서에는 그 공종의 실적이
     빠져 있다는 사실을 명시하세요 — 없는 것을 있는 것처럼 쓰면 안 됩니다.
   - `tradeReports.held` 가 0 이 아니면 attention 에 쓰세요(사람 확인을 기다리는 항목).
   - 어떤 행의 `note` 에 「사진 판독 중」이 들어 있으면 그 공종은 <b>제출은 했지만 내용이
     아직 안 읽힌</b> 상태입니다. attention 에 그 사실을 쓰세요 — 그 공종의 highlights 가
     비어 있는 것은 일을 안 해서가 아닙니다. 「판독 실패」가 들어 있으면 사진을 다시
     올려야 한다고 쓰세요.
3-3. `schedule.rate` 는 **공정표에서 계산된** 현장 공정률이고, `field.progressRate` 는
   현장이 손으로 적은 진도율입니다. 둘 다 있으면 progressNote 에 **둘 다** 쓰고,
   차이가 크면(10%p 이상) 그 사실을 짚으세요. 둘을 평균 내거나 하나만 고르지 마세요.
3-1. `actions` 는 공정·자재·인원 어디에도 안 들어가는 실무 항목입니다(원청 지시, 승인,
   의사결정, 준비물). **여기 있는 내용을 반드시 보고서에 반영하세요.**
   - `actions.doneToday`  → done 배열(오늘 한 일)에 넣으세요
   - `actions.tomorrow` + `actions.undated` → tomorrow(내일 할 일)에 넣으세요
   - `actions.blockers` 와 `actions.overdue` → attention(오늘 안에 확인) **최상단**에 넣으세요.
     막힘 항목은 이게 안 되면 다른 작업이 멈추므로 가장 먼저 써야 합니다.
4. 톤: 사실 위주, 담백하게. 과장·미사여구 금지. 각 항목은 한국어로.

## 반환 항목
- headline    : 오늘 하루를 한 문장으로(예: "협력사 3개사 14명 출역, 3층 트레이 포설 60% 도달")
- done        : 문자열 배열. **오늘 한 일** — 진도·자재·승인·처리한 지시를 항목별로. 없으면 빈 배열
- summary     : 3~5문장 총평. 인원·진도·자재를 아우를 것
- laborNote   : 인원에 대한 한 문단. gap 이 있으면 반드시 언급
- progressNote: 공정 진행에 대한 한 문단. 없으면 "보고된 진도 없음"
- issues      : 문자열 배열. 오늘의 이슈·위험·지연 요인. 없으면 빈 배열
- tomorrow    : 문자열 배열. 내일 확인·조치가 필요한 일. 없으면 빈 배열
- attention   : 문자열 배열. 관리자가 **오늘 안에** 확인해야 할 것(미퇴근, 미확인 인원, 대기 중인 반영 등)
PROMPT;

        $schema = [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'done' => ['type' => 'array', 'items' => ['type' => 'string']],
                'summary' => ['type' => 'string'],
                'laborNote' => ['type' => 'string'],
                'progressNote' => ['type' => 'string'],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tomorrow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'attention' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['headline', 'summary', 'laborNote', 'progressNote'],
        ];

        try {
            // 사진 없이 텍스트만 — OcrEngine 은 이미지가 없어도 프롬프트만으로 동작한다.
            $result = $this->engine->analyze([], $prompt, $schema);

            return is_array($result['data'] ?? null) ? $result['data'] : [];
        } catch (\Throwable $e) {
            Log::warning('일일 마감 보고서 서술 생성 실패, 집계만 저장: '.$e->getMessage());

            // AI 가 실패해도 보고서는 남아야 한다 — 숫자만으로도 마감은 성립한다.
            return [
                'headline' => '자동 요약을 생성하지 못했습니다(집계는 정상).',
                'done' => [],
                'summary' => '',
                'laborNote' => '',
                'progressNote' => '',
                'issues' => [],
                'tomorrow' => [],
                'attention' => [],
                'aiFailed' => true,
            ];
        }
    }

    /**
     * 저장된 보고서 조회(폴링·열람 겸용).
     *
     * @return array<string, mixed>
     */
    public function show(int $reportId): array
    {
        $report = DailyClosingReport::with('closedBy')->find($reportId);
        if (! $report) {
            return ['success' => false, 'error' => '보고서를 찾을 수 없습니다.'];
        }

        return [
            'success' => true,
            'id' => $report->id,
            'status' => $report->status,
            'error' => $report->error,
            'date' => $report->report_date->toDateString(),
            'closedBy' => $report->closedBy?->name,
            'closedAt' => $report->closed_at?->format('Y-m-d H:i'),
            'metrics' => $this->withLiveTradeReports($report),
            'narrative' => $report->narrative ?: [],
            'field' => $report->hasFieldReport() ? $report->fieldReport() : null,
        ];
    }

    /**
     * 저장된 집계에 <b>공종별 보고만</b> 지금 값으로 갈아 끼운다.
     *
     * 마감 집계는 소장이 버튼을 누른 순간에 얼어붙는다. 그런데 공종별 보고는
     * 그 뒤에도 들어온다 — 마감 시각이 17시인데 마감을 16시 20분에 눌렀다면,
     * 16시 50분에 낸 덕트 반장의 보고는 얼어붙은 사진에 없다. 18시 30분에
     * 원청으로 나가는 메일에는 그 공종이 「미제출」로 찍히고, 「해당 공종의
     * 금일 실적은 이 보고서에 포함되지 않았습니다」까지 붙는다. 실적은 ERP 에
     * 들어 있고 반장은 제출했는데도.
     *
     * 미제출을 드러내려던 장치가 정반대로, 없는 미제출을 만들어 내는 셈이라
     * 이 블록만은 얼리지 않는다(board() 한 번이라 비용도 거의 없다).
     *
     * @return array<string, mixed>
     */
    public function withLiveTradeReports(DailyClosingReport $report): array
    {
        $metrics = $report->metrics ?: [];

        // 옛 보고서에는 이 블록 자체가 없다. 그때는 건드리지 않는다 — 지난 보고서에
        // 오늘 계산한 값을 끼워 넣으면 그건 기록이 아니라 재구성이다.
        if (! array_key_exists('tradeReports', $metrics)) {
            return $metrics;
        }

        try {
            $metrics['tradeReports'] = $this->tradeBoard(
                $report->site_id,
                $report->report_date->toDateString(),
            );
        } catch (\Throwable $e) {
            report($e); // 갈아 끼우기에 실패해도 저장된 집계로 보고서는 나가야 한다.
        }

        return $metrics;
    }

    /**
     * 최근 마감 보고서 목록.
     *
     * @return array<string, mixed>
     */
    public function recent(?int $siteId, int $limit = 30): array
    {
        $rows = DailyClosingReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('report_date')->limit($limit)->with('closedBy')->get();

        return [
            'success' => true,
            'reports' => $rows->map(fn (DailyClosingReport $r): array => [
                'id' => $r->id,
                'date' => $r->report_date->toDateString(),
                'status' => $r->status,
                'headline' => (string) (($r->narrative['headline'] ?? '')),
                'reported' => (int) (($r->metrics['labor']['reported'] ?? 0)),
                'actualQr' => (int) (($r->metrics['labor']['actualQr'] ?? 0)),
                // 그날 최종 인원. 이 값이 없던 예전 보고서는 QR 실적을 그대로 쓴다 —
                // 팀 마감이 없었다면 최종 인원이 곧 QR 실적이라 값이 같다.
                'final' => (int) (($r->metrics['labor']['final'] ?? $r->metrics['labor']['actualQr'] ?? 0)),
                'closedBy' => $r->closedBy?->name,
                // 현장은 썼는데 마감을 안 누른 날. 목록에서 이게 안 보이면 빠진 날을
                // 아무도 모른다 — 예전에는 그 기록이 다른 표에 있어서 여기 안 나왔다.
                'fieldStatus' => $r->field_status,
                'hasField' => $r->hasFieldReport(),
            ])->all(),
        ];
    }
}
