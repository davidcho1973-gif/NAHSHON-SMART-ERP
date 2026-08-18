<?php

namespace App\Services\Ops;

use App\Jobs\WriteDailyClosingReportJob;
use App\Models\DailyClosingReport;
use App\Models\DailyCrewReport;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Services\Attendance\DailyHeadcountService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Carbon;
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
        $actualTotal = (int) ($actual['direct']['count'] + $actual['indirect']['count'] + $actual['other']['count']);

        $crew = $this->crewSummary($siteId, $date);

        // ── 상황실 활동
        $batches = OpsIntakeBatch::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('created_at', $date)->get();

        $items = OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereIn('ops_intake_batch_id', $batches->pluck('id'))
            ->get();

        $byCategory = $items->groupBy('category')->map->count();

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

            // 현장소장이 현장앱에서 직접 쓴 것. 날씨·오늘 한 일·내일 할 일·진도율·TBM.
            // 사람이 본 것이라 이 보고서에서 가장 1차 사실에 가깝고, AI 서술은 이걸
            // 다시 쓰는 게 아니라 근거로 삼는다.
            'field' => $report && $report->hasFieldReport() ? $report->fieldReport() : null,
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
            'metrics' => $report->metrics ?: [],
            'narrative' => $report->narrative ?: [],
            'field' => $report->hasFieldReport() ? $report->fieldReport() : null,
        ];
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
