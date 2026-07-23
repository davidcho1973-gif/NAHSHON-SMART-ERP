<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\MemberDocument;
use App\Models\PayrollRun;
use App\Models\ProcurementItem;
use App\Models\ProjectContractDocument;
use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Carbon;

/**
 * 현장 일일 운영(②) + 리스크·예외(③) 통합 대시보드 — 전부 실데이터 집계.
 *
 * 출역·오늘작업·TBM·자재도착은 "오늘" 스냅샷으로, 조달지연·안전·서류만료·승인대기는
 * 조치 필요 항목만 트리아지(즉시조치/주의/정상)로 올린다.
 */
class DashboardService
{
    public function overview(string $siteId = 'ALL'): array
    {
        $siteRowId = $this->resolveSiteId($siteId);
        $today = Carbon::today()->toDateString();

        $attendance = $this->attendance($siteRowId, $today);
        $tasks = $this->todayTasks($siteRowId, $today);
        $safety = $this->safety($siteRowId, $today);
        $procure = $this->procurement($siteRowId, $today);
        $docs = $this->expiringDocs($siteRowId);
        $payrollPending = $this->payrollPending($siteRowId);

        // ── 리스크 트리아지 ──
        $critical = [];
        $warning = [];

        foreach ($procure['critical'] as $p) {
            $critical[] = ['module' => '조달', 'title' => $p['name'], 'detail' => $p['detail']];
        }
        foreach ($tasks['highRiskNoPlan'] as $t) {
            $critical[] = ['module' => '안전', 'title' => $t.' · 고위험 작업', 'detail' => '안전계획(PTW) 미발행 상태'];
        }
        foreach ($docs['d7'] as $d) {
            $critical[] = ['module' => '서류', 'title' => $d['title'], 'detail' => $d['detail']];
        }
        foreach ($tasks['overdueCritical'] as $t) {
            $critical[] = ['module' => '공정', 'title' => $t.' · 임계경로 지연', 'detail' => '계획 종료일 경과 · 준공 영향'];
        }

        foreach ($procure['warning'] as $p) {
            $warning[] = ['module' => '조달', 'title' => $p['name'], 'detail' => $p['detail']];
        }
        if ($safety['tbmWaiting'] > 0) {
            $warning[] = ['module' => '안전', 'title' => 'TBM 서명 대기 '.$safety['tbmWaiting'].'건', 'detail' => '작업 전 서명이 완료되어야 진행 가능'];
        }
        if ($safety['openIssues'] > 0) {
            $warning[] = ['module' => '안전', 'title' => '미조치 안전 이슈 '.$safety['openIssues'].'건', 'detail' => '마감 전 조치 필요'];
        }
        if ($payrollPending > 0) {
            $warning[] = ['module' => '급여', 'title' => '급여 정산 승인 대기 '.$payrollPending.'건', 'detail' => '확정 후 지급 가능'];
        }
        if ($docs['d30'] > 0) {
            $warning[] = ['module' => '서류', 'title' => '만료 임박 서류 '.$docs['d30'].'건', 'detail' => '30일 이내 만료 · 갱신 준비'];
        }

        // ── 정상 요약 ──
        $normal = [
            ['label' => '출역 '.$attendance['rate'].'%', 'detail' => $attendance['present'].'/'.$attendance['planned'].'명 출근'],
            ['label' => '조달 정시 '.$procure['onTime'].'건', 'detail' => '전체 '.$procure['total'].'건 추적 중'],
            ['label' => '오늘 안전카드 '.$safety['cards'].'건', 'detail' => 'TBM 완료 '.$safety['tbmDone'].'건'],
            ['label' => '문서 신규 '.$docs['weekNew'].'건', 'detail' => '이번 주 분류·저장'],
        ];

        return [
            'success' => true,
            'date' => $today,
            'scope' => ['siteId' => $siteId, 'label' => $this->siteLabel($siteRowId, $siteId)],
            'risk' => [
                'critical' => $critical,
                'warning' => $warning,
                'normal' => $normal,
                'counts' => ['critical' => count($critical), 'warning' => count($warning)],
            ],
            'ops' => [
                'attendance' => $attendance,
                'tasks' => ['total' => $tasks['total'], 'critical' => $tasks['critical'], 'highRisk' => $tasks['highRisk'], 'list' => $tasks['list']],
                'tbm' => ['cards' => $safety['cards'], 'done' => $safety['tbmDone'], 'waiting' => $safety['tbmWaiting']],
                'arrivals' => ['total' => count($procure['arrivals']), 'inTransit' => $procure['inTransit'], 'list' => $procure['arrivals']],
                'issues' => $safety['issueList'],
            ],
            'aiBrief' => $this->brief($critical, $warning),
        ];
    }

    // ───────────────────────── 집계 ─────────────────────────

    private function attendance(?int $siteRowId, string $today): array
    {
        $present = AttendanceLog::query()
            ->where('attendance_date', $today)->where('event_type', 'clock_in')
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))
            ->distinct()->count('employee_id');

        $planned = Employee::query()->where('employment_status', 'active')
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))->count();

        $byCompany = AttendanceLog::query()
            ->where('attendance_date', $today)->where('event_type', 'clock_in')
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))
            ->selectRaw('company_id, count(distinct employee_id) as c')->groupBy('company_id')
            ->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['name' => Company::query()->whereKey($r->company_id)->value('name') ?: '기타', 'count' => (int) $r->c])
            ->all();

        return [
            'present' => $present,
            'planned' => $planned,
            'absent' => max(0, $planned - $present),
            'rate' => $planned > 0 ? (int) round($present / $planned * 100) : 0,
            'byCompany' => $byCompany,
        ];
    }

    private function todayTasks(?int $siteRowId, string $today): array
    {
        $subs = WbsItem::query()->where('level', WbsItem::LEVEL_SUBTASK)
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))
            ->with(['safetyWorkItems' => fn ($q) => $q->where('work_date', $today)])
            ->get()
            ->filter(function (WbsItem $i) use ($today): bool {
                if ($i->status === WbsItem::STATUS_DONE || $i->looksLikeProcurement()) {
                    return false;
                }
                $s = $i->planned_start?->toDateString();
                $e = $i->planned_end?->toDateString();
                if ($s === null || $e === null) {
                    return $i->status === WbsItem::STATUS_IN_PROGRESS;
                }

                return $s <= $today && $today <= $e;
            });

        $highRiskNoPlan = [];
        $overdueCritical = [];
        $list = [];
        foreach ($subs as $i) {
            $card = $i->safetyCardFor($today);
            $needsPlan = (float) $i->crew_size > 0 && $card === null;
            if ($i->ehs === 'high' && $card === null) {
                $highRiskNoPlan[] = $i->name;
            }
            if ($i->is_critical && $i->planned_end && $i->planned_end->toDateString() < $today) {
                $overdueCritical[] = $i->name;
            }
            $list[] = [
                'activityId' => $i->activity_id,
                'name' => $i->name,
                'trade' => $i->trade ?? '',
                'crewSize' => $i->crew_size !== null ? (float) $i->crew_size : null,
                'isCritical' => (bool) $i->is_critical,
                'ehsHigh' => $i->ehs === 'high',
                'nextAction' => $needsPlan ? '안전계획' : ($card && ! $card->isTbmCleared() ? 'TBM' : ($i->status !== WbsItem::STATUS_IN_PROGRESS ? '시작' : '진행중')),
            ];
        }

        // 임계경로 먼저.
        usort($list, fn ($a, $b) => ($b['isCritical'] <=> $a['isCritical']));

        return [
            'total' => $subs->count(),
            'critical' => $subs->where('is_critical', true)->count(),
            'highRisk' => $subs->where('ehs', 'high')->count(),
            'highRiskNoPlan' => $highRiskNoPlan,
            'overdueCritical' => $overdueCritical,
            'list' => array_slice($list, 0, 6),
        ];
    }

    private function safety(?int $siteRowId, string $today): array
    {
        $cards = SafetyWorkItem::query()->where('work_date', $today)
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))
            ->with('signatures')->get();

        $tbmDone = $cards->filter(fn (SafetyWorkItem $c) => $c->isTbmCleared())->count();

        $cardIds = SafetyWorkItem::query()
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))->pluck('id');
        $openIssues = SafetyWorkIssue::query()->whereIn('safety_work_item_id', $cardIds)
            ->whereNotIn('status', ['조치완료', '완료', 'resolved', 'closed'])->count();

        $issueList = SafetyWorkIssue::query()->whereIn('safety_work_item_id', $cardIds)
            ->whereNotIn('status', ['조치완료', '완료', 'resolved', 'closed'])
            ->latest()->limit(5)->get()
            ->map(fn (SafetyWorkIssue $i) => ['kind' => $i->type ?: '이슈', 'title' => (string) $i->body, 'detail' => $i->status ?: '조치중'])
            ->all();

        return [
            'cards' => $cards->count(),
            'tbmDone' => $tbmDone,
            'tbmWaiting' => $cards->count() - $tbmDone,
            'openIssues' => $openIssues,
            'issueList' => $issueList,
        ];
    }

    private function procurement(?int $siteRowId, string $today): array
    {
        $rows = ProcurementItem::query()
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))
            ->with('wbsItem')->get()
            ->map(function (ProcurementItem $t) {
                $wbs = $t->wbsItem;
                $needBy = $wbs?->planned_end?->toDateString();
                $eta = $t->eta?->toDateString();
                $slack = ($eta !== null && $needBy !== null)
                    ? (int) Carbon::parse($eta)->diffInDays(Carbon::parse($needBy), false) : null;
                $delay = $t->status === '입고완료' ? 'done'
                    : (($eta === null || $slack === null) ? 'unknown' : ($slack < 0 ? 'late' : ($slack <= 7 ? 'risk' : 'ok')));
                $critical = (bool) ($wbs?->is_critical);
                $alert = $delay === 'late' ? ($critical ? 'critical' : 'warning')
                    : ($delay === 'risk' ? ($critical ? 'warning' : 'watch') : 'none');

                return [
                    'name' => $wbs?->name ?: ($t->po_no ?: '조달 항목'),
                    'vendor' => $t->vendor,
                    'status' => $t->status,
                    'eta' => $eta,
                    'slack' => $slack,
                    'delay' => $delay,
                    'alert' => $alert,
                ];
            });

        $detail = function (array $p): string {
            if ($p['delay'] === 'late') {
                return '임계경로 · ETA가 납기보다 '.abs((int) $p['slack']).'일 지연';
            }
            if ($p['delay'] === 'risk') {
                return '납기 임박 D-'.max(0, (int) $p['slack']).' · '.($p['vendor'] ?: '');
            }

            return (string) ($p['vendor'] ?? '');
        };

        return [
            'total' => $rows->count(),
            'onTime' => $rows->whereIn('delay', ['ok', 'done'])->count(),
            'inTransit' => $rows->whereIn('status', ['선적중', '통관중'])->count(),
            'critical' => $rows->where('alert', 'critical')->map(fn ($p) => ['name' => $p['name'], 'detail' => $detail($p)])->values()->all(),
            'warning' => $rows->where('alert', 'warning')->map(fn ($p) => ['name' => $p['name'], 'detail' => $detail($p)])->values()->all(),
            'arrivals' => $rows->filter(fn ($p) => $p['eta'] === $today && $p['status'] !== '입고완료')
                ->map(fn ($p) => ['name' => $p['name'], 'vendor' => $p['vendor'], 'status' => $p['status'], 'delay' => $p['delay']])->values()->all(),
        ];
    }

    /**
     * @return array{d7: array<int, array<string,string>>, d30: int, weekNew: int}
     */
    private function expiringDocs(?int $siteRowId): array
    {
        $today = Carbon::today();
        $d7 = [];
        $d30 = 0;

        $sources = [
            ['model' => IntegratedDocument::class, 'label' => '문서', 'site' => true],
            ['model' => MemberDocument::class, 'label' => 'HR 서류', 'site' => false],
            ['model' => ProjectContractDocument::class, 'label' => '계약 서류', 'site' => false],
        ];
        foreach ($sources as $s) {
            $near = $s['model']::query()
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                ->when($siteRowId && $s['site'], fn ($q) => $q->where('site_id', $siteRowId))
                ->limit(10)->get();
            foreach ($near as $doc) {
                $days = (int) $today->diffInDays(Carbon::parse($doc->expires_on), false);
                $d7[] = ['title' => (string) ($doc->title ?: $s['label']), 'detail' => $s['label'].' · 만료 D-'.max(0, $days)];
            }
            $d30 += $s['model']::query()
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [$today->copy()->addDays(8)->toDateString(), $today->copy()->addDays(30)->toDateString()])
                ->when($siteRowId && $s['site'], fn ($q) => $q->where('site_id', $siteRowId))
                ->count();
        }

        $weekNew = IntegratedDocument::query()
            ->where('created_at', '>=', $today->copy()->subDays(7))
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))->count();

        return ['d7' => array_slice($d7, 0, 6), 'd30' => $d30, 'weekNew' => $weekNew];
    }

    private function payrollPending(?int $siteRowId): int
    {
        // 급여 대장은 기간·회사 단위(현장 스코프 컬럼 없음) — 전사 기준 승인 대기 수.
        return PayrollRun::query()
            ->whereNotIn('status', ['approved', 'paid', 'completed'])
            ->count();
    }

    private function brief(array $critical, array $warning): string
    {
        if ($critical !== []) {
            $top = $critical[0];
            $more = count($critical) - 1;
            return sprintf('오늘 최우선은 [%s] %s 입니다. %s%s',
                $top['module'], $top['title'], $top['detail'],
                $more > 0 ? " 그 외 즉시조치 {$more}건이 있습니다." : ' 즉시 처리 후 작업을 진행하세요.');
        }
        if ($warning !== []) {
            return sprintf('즉시조치 항목은 없습니다. 주의 %d건(%s 등)을 오늘 중 확인하세요.',
                count($warning), $warning[0]['title']);
        }

        return '조치가 필요한 리스크가 없습니다. 모든 지표가 정상 범위입니다.';
    }

    private function resolveSiteId(string $siteId): ?int
    {
        $siteId = trim($siteId);
        if ($siteId === '' || in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            return null;
        }
        if (is_numeric($siteId)) {
            return (int) $siteId;
        }
        $code = str_contains($siteId, ' - ') ? trim(strstr($siteId, ' - ', true)) : $siteId;

        return Site::query()->where('code', $siteId)->orWhere('code', $code)->orWhere('name', $siteId)->value('id');
    }

    private function siteLabel(?int $siteRowId, string $siteId): string
    {
        if ($siteRowId === null) {
            return '전 현장';
        }

        return (string) (Site::query()->whereKey($siteRowId)->value('name') ?: $siteId);
    }
}
