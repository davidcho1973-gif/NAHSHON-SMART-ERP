<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 계약이 요구하는 인증임금(prevailing wage / certified payroll)을 급여가 읽게 한다.
 *
 * project_contracts 와 projects 에 certified_payroll_required · prevailing_wage_required
 * 스위치가 있는데 급여 엔진이 이 값을 읽지 않았다. WH-347 인증 임금대장을 내야 하는
 * 계약인데도 일반 급여로 계산되고, 담당자가 기억해서 따로 챙겨야 했다.
 *
 * 미국 공공·원청 현장에서 이건 단순 누락이 아니라 컴플라이언스 문제다. 계약 등록 때 켜 둔
 * 스위치가 정산에 아무 영향을 안 준다면 그 스위치는 없는 것과 같다.
 *
 * 판단은 보수적으로 한다 — 애매하면 "대상"으로 본다. 대상인데 놓치는 것은 법적 문제이고,
 * 대상이 아닌데 표시되는 것은 확인 한 번이면 끝나는 일이다.
 */
class CertifiedPayrollRequirement
{
    /**
     * 아직 시작 전이거나 취소된 계약은 이번 급여에 영향을 주지 않는다.
     * 그 밖의 상태(active / expiring / 기타)는 유효한 것으로 본다.
     */
    private const INACTIVE_CONTRACT_STATUSES = ['draft', 'cancelled', 'terminated', 'expired'];

    /**
     * 이 기간·이 현장 범위의 급여가 인증임금 대상인지.
     *
     * @param  string  $siteScope  급여 배치의 현장 범위 — 'ALL' 이거나 sites.code
     * @return array{
     *   required: bool,
     *   headcount: int,
     *   sites: array<int, array{id: int, code: ?string, name: ?string}>,
     *   sources: array<int, array{type: string, id: int, label: string, site: ?string}>
     * }
     */
    public function forPeriod(string $siteScope, Carbon $start, Carbon $end): array
    {
        $siteIds = $this->siteIdsInScope($siteScope);
        $sources = array_merge(
            $this->contractSources($siteIds, $start, $end),
            $this->projectSources($siteIds),
        );

        if ($sources === []) {
            return ['required' => false, 'headcount' => 0, 'sites' => [], 'sources' => []];
        }

        // 요건을 만든 현장만 대상이다. 'ALL' 배치라도 인증임금 현장이 하나뿐이면
        // 그 현장 인원만 WH-347 에 들어간다.
        $affectedSiteIds = array_values(array_unique(array_filter(
            array_column($sources, 'siteId'),
            fn ($id) => $id !== null,
        )));

        return [
            'required' => true,
            'headcount' => $this->headcountFor($affectedSiteIds),
            'sites' => $this->siteLabels($affectedSiteIds),
            'sources' => array_map(fn (array $s): array => [
                'type' => $s['type'],
                'id' => $s['id'],
                'label' => $s['label'],
                'site' => $s['siteName'],
            ], $sources),
        ];
    }

    /** 급여 배치가 이미 만들어진 뒤에 같은 판단을 다시 하려면. */
    public function forRun(PayrollRun $run): array
    {
        return $this->forPeriod(
            (string) ($run->site_scope ?: 'ALL'),
            Carbon::parse($run->period_start),
            Carbon::parse($run->period_end),
        );
    }

    /** @return array<int, int> */
    private function siteIdsInScope(string $siteScope): array
    {
        if (! Schema::hasTable('sites')) {
            return [];
        }

        return Site::query()
            ->when($siteScope !== 'ALL', fn ($q) => $q->where('code', $siteScope))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{type: string, id: int, label: string, siteId: ?int, siteName: ?string}>
     */
    private function contractSources(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === [] || ! Schema::hasTable('project_contracts')) {
            return [];
        }

        return ProjectContract::query()
            ->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q->where('certified_payroll_required', true)
                ->orWhere('prevailing_wage_required', true))
            ->whereNotIn('status', self::INACTIVE_CONTRACT_STATUSES)
            // 기간이 안 적힌 계약은 배제하지 않는다. 날짜를 안 넣었다고 해서
            // 인증임금 요건이 사라지는 것은 아니다.
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $end->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString()))
            ->with('site:id,name')
            ->get()
            ->map(fn (ProjectContract $c): array => [
                'type' => 'contract',
                'id' => (int) $c->id,
                'label' => (string) ($c->title ?: $c->contract_number ?: $c->internal_reference),
                'siteId' => $c->site_id ? (int) $c->site_id : null,
                'siteName' => $c->site?->name,
            ])->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{type: string, id: int, label: string, siteId: ?int, siteName: ?string}>
     */
    private function projectSources(array $siteIds): array
    {
        if ($siteIds === [] || ! Schema::hasTable('projects')) {
            return [];
        }

        return Project::query()
            ->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q->where('certified_payroll_required', true)
                ->orWhere('prevailing_wage_required', true))
            ->with('site:id,name')
            ->get()
            ->map(fn (Project $p): array => [
                'type' => 'project',
                'id' => (int) $p->id,
                'label' => (string) ($p->name ?: $p->project_code),
                'siteId' => $p->site_id ? (int) $p->site_id : null,
                'siteName' => $p->site?->name,
            ])->all();
    }

    /** @param  array<int, int>  $siteIds */
    private function headcountFor(array $siteIds): int
    {
        if ($siteIds === [] || ! Schema::hasTable('employees')) {
            return 0;
        }

        return Employee::query()
            ->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q->whereNull('employment_status')->orWhere('employment_status', '!=', 'terminated'))
            ->count();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{id: int, code: ?string, name: ?string}>
     */
    private function siteLabels(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return Site::query()->whereIn('id', $siteIds)->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Site $s): array => ['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name])
            ->all();
    }
}
