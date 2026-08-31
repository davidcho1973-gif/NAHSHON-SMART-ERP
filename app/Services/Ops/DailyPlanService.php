<?php

namespace App\Services\Ops;

use App\Models\DailyClosingReport;
use App\Models\Equipment;
use App\Models\OpsLaborReport;
use App\Models\SafetyPermit;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * 아침 작업계획서 — TBM 직후 원청에 내는 "오늘 이렇게 일합니다".
 *
 * 지금까지 이 문서는 사람 머릿속과 카톡에만 있었다. ERP 안에는 재료가 다 있었는데
 * (전날 마감의 "내일 할 일", 안전 작업카드, 작업허가서, 장비 대장, 어제 출역 인원)
 * 그것들을 한 장으로 모으는 자리가 없었을 뿐이다.
 *
 * 그래서 이 서비스가 하는 일은 <b>글쓰기가 아니라 모으기</b>다. 화면을 열면 이미
 * 절반 이상이 채워져 있고, 현장소장은 작업 내용·위험요인·특이사항만 손보면 된다.
 * 빈 종이를 주면 아무도 안 쓴다 — 이게 이 기능의 유일한 설계 원칙이다.
 */
class DailyPlanService
{
    public function __construct(
        private readonly OpsActionService $actions,
    ) {}

    /**
     * 그날의 계획서 — 저장된 것이 있으면 그것을, 없으면 자동으로 모은 초안을.
     *
     * @return array<string, mixed>
     */
    public function get(?int $siteId, ?string $date = null): array
    {
        $date = $this->resolveDate($siteId, $date);

        $report = DailyClosingReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->whereDate('report_date', $date)
            ->first();

        $suggested = $this->suggest($siteId, $date);
        $saved = $report?->plan ?: [];

        return [
            'success' => true,
            'date' => $date,
            'site' => $this->siteInfo($siteId),
            'reportId' => $report?->id,
            'status' => $report?->plan_status ?: DailyClosingReport::PLAN_DRAFT,
            'submittedAt' => $report?->plan_submitted_at?->format('Y-m-d H:i'),
            'submittedBy' => $report?->planBy?->name,
            // 사람이 손댄 것이 있으면 그것이 우선이고, 자동 수집분은 참고로 함께 내려간다.
            // 화면에서 "ERP 가 채운 칸" 을 표시해 주려면 둘이 따로 있어야 한다.
            'plan' => $saved !== [] ? $saved : $suggested,
            'suggested' => $suggested,
            'isNew' => $saved === [],
        ];
    }

    /**
     * ERP 가 아는 것으로 계획서 초안을 만든다.
     *
     * @return array<string, mixed>
     */
    public function suggest(?int $siteId, string $date): array
    {
        $yesterday = Carbon::parse($date)->subDay()->toDateString();

        // ── 오늘 작업: 안전 작업카드가 가장 정확하다. 이미 공정(WBS)에 매달려 있고
        //    작업 위치·인원·물량까지 들어 있어 계획서가 요구하는 것과 모양이 같다.
        $cards = SafetyWorkItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('work_date', $date)
            ->with('company:id,name')
            ->orderBy('id')
            ->get();

        $crews = $cards->map(fn (SafetyWorkItem $c): array => [
            'company' => $c->company?->name ?: '',
            'trade' => (string) ($c->wbs_code ?: ''),
            'headcount' => (int) $c->crew,
            'location' => (string) $c->location,
            'work' => (string) $c->title,
            'source' => '안전 작업카드',
        ])->values()->all();

        // 작업카드가 아직 없는 아침이면 어제 출역 인원을 그대로 제안한다.
        // 대개 같은 팀이 같은 일을 이어서 하므로, 빈 표보다 훨씬 낫다.
        if ($crews === []) {
            $crews = OpsLaborReport::query()
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->where('work_date', $yesterday)
                ->with('company')
                ->get()
                ->map(fn (OpsLaborReport $r): array => [
                    'company' => $r->label(),
                    'trade' => (string) $r->trade,
                    'headcount' => (int) $r->headcount,
                    'location' => '',
                    'work' => '',
                    'source' => '어제 출역(확인 필요)',
                ])->values()->all();
        }

        // ── 오늘 유효한 작업허가서(PTW)
        $permits = SafetyPermit::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->whereNotIn('status', ['취소', '만료'])
            ->orderBy('id')
            ->get(['permit_no', 'type', 'title', 'status'])
            ->map(fn (SafetyPermit $p): array => [
                'no' => (string) $p->permit_no,
                'type' => (string) $p->type,
                'title' => (string) $p->title,
                'status' => (string) $p->status,
            ])->values()->all();

        // ── 오늘 쓸 장비: 지금 '사용중' 인 것을 먼저 제안한다.
        //    현장에 있는 장비를 전부 채우면 안 된다 — 703K 는 143대라 계획서가
        //    장비 목록으로 뒤덮인다. 실제로 돌고 있는 것만 올리고 나머지는 사람이 더한다.
        $onSite = Equipment::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('status', '<>', '정비중')
            ->orderBy('equipment_type')
            ->get(['equipment_code', 'equipment_type', 'model', 'status']);

        $picked = $onSite->where('status', '사용중');
        // 상태를 아무도 안 바꿔 둔 현장도 있다. 그럴 때는 빈 표보다 몇 대라도 보여 준다.
        if ($picked->isEmpty()) {
            $picked = $onSite->take(8);
        }

        $equipment = $picked->take(20)->map(fn (Equipment $e): array => [
            'name' => trim((string) $e->equipment_type.' '.(string) $e->model),
            'code' => (string) $e->equipment_code,
            'use' => '',
            'status' => (string) $e->status,
        ])->values()->all();

        // ── 어제 마감이 남긴 "내일 할 일" — 오늘 계획의 출발점이다.
        $prev = DailyClosingReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->whereDate('report_date', $yesterday)
            ->first();

        $carryOver = [];
        if ($prev) {
            if (filled($prev->work_tomorrow)) {
                $carryOver[] = (string) $prev->work_tomorrow;
            }
            foreach (($prev->narrative['tomorrow'] ?? []) as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $carryOver[] = $line;
                }
            }
        }

        // 액션 아이템에서 "오늘 마감" 인 것들 — 원청 지시·승인·준비물.
        foreach ($this->actions->board($siteId, $date)['today'] ?? [] as $row) {
            $carryOver[] = (string) ($row['title'] ?? '');
        }

        $carryOver = array_values(array_filter(array_unique($carryOver)));

        return [
            'weather' => '',
            'temperature' => '',
            'tbmTime' => '07:00',
            'tbmLeader' => '',
            'tbmHeadcount' => array_sum(array_column($crews, 'headcount')),
            'workScope' => implode("\n", array_map(fn (string $l): string => '· '.$l, $carryOver)),
            'crews' => $crews,
            'equipment' => $equipment,
            'permits' => $permits,
            // 위험요인은 자동으로 채우지 않는다. PTP/JHA 는 그날 그 작업을 보고
            // 사람이 판단해야 하는 것이고, 기계가 채운 위험요인은 아무도 안 읽는다.
            'hazards' => [],
            'notes' => '',
        ];
    }

    /**
     * 계획서 저장(초안). 마감 보고서와 같은 줄에 들어간다.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(?int $siteId, ?string $date, array $payload, ?int $userId = null, bool $submit = false): array
    {
        $date = $this->resolveDate($siteId, $date);

        $plan = $this->clean($payload);

        $report = DailyClosingReport::firstOrNew(
            ['site_id' => $siteId, 'report_date' => $date],
        );

        // 처음 만들어지는 줄이면 마감 상태는 아직 'open' 이다 — 아침에 계획서를 썼다고
        // 그날이 마감된 것은 아니다. 두 상태를 섞으면 저녁에 마감이 안 눌린 날을 놓친다.
        $report->status ??= DailyClosingReport::OPEN;
        $report->plan = $plan;
        $report->plan_by_id = $userId ?: $report->plan_by_id;

        if ($submit) {
            $report->plan_status = DailyClosingReport::PLAN_SUBMITTED;
            $report->plan_submitted_at = now();
        } elseif (! $report->plan_status) {
            $report->plan_status = DailyClosingReport::PLAN_DRAFT;
        }

        $report->save();

        return [
            'success' => true,
            'reportId' => $report->id,
            'date' => $date,
            'status' => $report->plan_status,
            'message' => $submit ? '작업계획서를 제출했습니다.' : '작업계획서를 저장했습니다.',
        ];
    }

    /**
     * 화면에서 올라온 것을 저장 가능한 모양으로 다듬는다.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function clean(array $in): array
    {
        $rows = function (string $key, array $fields) use ($in): array {
            $out = [];
            foreach ((array) ($in[$key] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($fields as $f) {
                    $clean[$f] = is_scalar($row[$f] ?? null) ? trim((string) $row[$f]) : '';
                }
                // 전부 빈 줄은 버린다 — 화면에서 추가만 하고 안 쓴 행이 그대로 보고서에 나가면 안 된다.
                if (implode('', $clean) !== '') {
                    $out[] = $clean;
                }
            }

            return $out;
        };

        $crews = $rows('crews', ['company', 'trade', 'headcount', 'location', 'work']);
        foreach ($crews as &$c) {
            $c['headcount'] = (int) $c['headcount'];
        }
        unset($c);

        return [
            'weather' => trim((string) ($in['weather'] ?? '')),
            'temperature' => trim((string) ($in['temperature'] ?? '')),
            'tbmTime' => trim((string) ($in['tbmTime'] ?? '')),
            'tbmLeader' => trim((string) ($in['tbmLeader'] ?? '')),
            'tbmHeadcount' => (int) ($in['tbmHeadcount'] ?? 0),
            'workScope' => trim((string) ($in['workScope'] ?? '')),
            'crews' => $crews,
            'equipment' => $rows('equipment', ['name', 'code', 'use']),
            'permits' => $rows('permits', ['no', 'type', 'title']),
            'hazards' => $rows('hazards', ['hazard', 'control']),
            'notes' => trim((string) ($in['notes'] ?? '')),
        ];
    }

    private function resolveDate(?int $siteId, ?string $date): string
    {
        if ($date) {
            return $date;
        }
        $tz = ($siteId ? Site::find($siteId)?->timezone : null) ?: config('app.timezone');

        return Carbon::now($tz)->toDateString();
    }

    /** @return array{id: int|null, name: string|null, code: string|null} */
    private function siteInfo(?int $siteId): array
    {
        $site = $siteId ? Site::find($siteId) : null;

        return ['id' => $siteId, 'name' => $site?->name, 'code' => $site?->code];
    }
}
